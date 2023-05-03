<?php

namespace Dedoc\Scramble\Infer;

use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\Reference\AbstractReferenceType;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Support\Str;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

class ProjectAnalyzer
{
    /** @var array<string, string> */
    private array $files = [];

    public array $symbols = [
        'function' => [],
        'class' => [],
        'constant' => [],
    ];

    /** @var array<int, array{0: 'function'|'class', 1: string}> */
    public array $queue = [];

    private array $analyzedSymbols = [];

    public function __construct(
        private FileParser $parser,
        public array $extensions = [],
        public array $handlers = [],
        public Index $index = new Index,
        private bool $shouldResolveReferences = true,
    ) {
    }

    public function files()
    {
        return $this->files;
    }

    public function addFile(string $path, ?string $content = null)
    {
        $content = $content ?: file_get_contents($path);

        $result = $this->parser->parseContent($content);

        $definitionNodes = (new NodeFinder)->find($result->getStatements(), function (Node $node) {
            if (
                $node instanceof Node\Stmt\Function_
                && $node->namespacedName->toString()
            ) {
                return true;
            }

            if (
                $node instanceof Node\Stmt\ClassLike
                && $node->namespacedName->toString()
            ) {
                return true;
            }

            return false;
        });

        foreach ($definitionNodes as $definitionNode) {
            $symbolType = $definitionNode instanceof Node\Stmt\Function_
                ? 'function'
                : 'class';

            $this->symbols[$symbolType][$name = $definitionNode->namespacedName->toString()] = $path;
            $this->queue[] = [$symbolType, $name];
        }

        $this->files[$path] = $content;

        return $this;
    }

    public function analyze()
    {
        $this->processQueue($this->queue);

        $this->resolveReferencesInIndex();
    }

    private function processQueue(array &$queue)
    {
        foreach ($queue as $i => [$type, $name]) {
            if (
                ! array_key_exists(implode('.', [$type, $name]), $this->analyzedSymbols)
                && isset($this->files[$this->symbols[$type][$name]])
            ) {
                // dump("Processing $type [$name]");
                $content = $this->files[$this->symbols[$type][$name]];

                $this->analyzeFileSymbol($content, [$type, $name]);
            }

            unset($queue[$i]);
        }
    }

    private function analyzeFileSymbol(string $content, array $symbol): void
    {
        // dump(['analyzeFileSymbol' => $symbol]);
        $this->analyzedSymbols[implode('.', $symbol)] = true;

        [$type, $name] = $symbol;
        $result = $this->parser->parseContent($content);

        $symbolDefinitionNode = (new NodeFinder)->findFirst($result->getStatements(), function (Node $node) use ($type, $name) {
            if ($type === 'function') {
                return $node instanceof Node\Stmt\Function_
                    && $node->namespacedName->toString() === $name;
            }

            if ($type === 'class') {
                return $node instanceof Node\Stmt\ClassLike
                    && $node->namespacedName->toString() === $name;
            }

            return false;
        });

        if (! $symbolDefinitionNode) {
            throw new \LogicException('Should not happen.');
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new TypeInferer(
            $this,
            $this->extensions,
            $this->handlers,
            $this->index,
            $result->getNameResolver(),
        ));

        $traverser->traverse([$symbolDefinitionNode]);
    }

    public function ensureParentDependenciesInIndex(Node\Stmt\Class_ $classNode)
    {
        $dependencies = array_values(array_filter([
            $classNode->extends instanceof Node\Name ? $classNode->extends->toString() : null,
            // TODO: Traits,
        ]));

        $queue = [];

        foreach ($dependencies as $className) {
            if (! isset($this->symbols['class'][$className]) && class_exists($className)) {
                $fileName = (new \ReflectionClass($className))->getFileName();

                // Not analyzing vendor deps.
                if (Str::contains($fileName, '/vendor/')) {
                    continue;
                }

                $this->symbols['class'][$className] = $fileName;

                $this->files[$fileName] ??= file_get_contents($fileName);
            }

            $queue[] = ['class', $className];
        }

        $this->processQueue($queue);
    }

    public function resolveReferencesInIndex()
    {
        /*
         * Now only one file a time gets traversed. So it is ok to simply take everything
         * added to index and check for reference types.
         *
         * At this point, if the function return types are not resolved, they aren't resolveable at all,
         * hence changed to the unknowns.
         *
         * When more files would be traversed in a single run (and index will be shared), this needs to
         * be re-implemented (maybe not).
         *
         * The intent here is to traverse symbols in index added through the file traversal. This logic
         * may be not applicable when analyzing multiple files per index. Pay attention to this as it may
         * hurt performance unless handled.
         */
        $resolveReferencesInFunctionReturn = function ($scope, $functionType) {
            if (! ReferenceTypeResolver::hasResolvableReferences($returnType = $functionType->getReturnType())) {
                return;
            }

            $resolvedReference = (new ReferenceTypeResolver($this->index))->resolve($scope, $returnType);

            if ($this->shouldResolveReferences && ReferenceTypeResolver::hasResolvableReferences($resolvedReference)) {
                $resolvedReference = (new TypeWalker)->replace($resolvedReference, fn ($t) => $t instanceof AbstractReferenceType ? new UnknownType() : null);
            }
            if ($resolvedReference instanceof AbstractReferenceType && $this->shouldResolveReferences) {
                $resolvedReference = new UnknownType();
            }

            $functionType->setReturnType(
                $resolvedReference->mergeAttributes($returnType->attributes())
            );
        };

        foreach ($this->index->functionsDefinitions as $functionDefinition) {
            $fnScope = new Scope(
                $this->index,
                new NodeTypesResolver,
                new ScopeContext(functionDefinition: $functionDefinition),
                new FileNameResolver(new NameContext(new Throwing())),
            );
            $resolveReferencesInFunctionReturn($fnScope, $functionDefinition->type);
        }

        foreach ($this->index->classesDefinitions as $classDefinition) {
            foreach ($classDefinition->methods as $name => $methodDefinition) {
                $methodScope = new Scope(
                    $this->index,
                    new NodeTypesResolver,
                    new ScopeContext($classDefinition, $methodDefinition),
                    new FileNameResolver(new NameContext(new Throwing())),
                );
                $resolveReferencesInFunctionReturn($methodScope, $methodDefinition->type);
            }
        }
    }
}
