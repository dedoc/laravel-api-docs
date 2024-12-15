<?php

namespace Dedoc\Scramble\Infer\Reflector;

use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Infer\Visitors\PhpDocResolver;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use ReflectionMethod;

class MethodReflector
{
    private static array $cache = [];

    private ?ClassMethod $methodNode = null;

    private function __construct(
        private FileParser $parser, public string $className, public string $name) {}

    public static function make(string $className, string $name)
    {
        return static::$cache["$className@$name"] = new static(
            app(FileParser::class), // ?
            $className,
            $name,
        );
    }

    public function getMethodCode(): string
    {
        $reflection = $this->getReflection();

        // The class may be a part of standard PHP classes.
        if (! $path = $reflection->getFileName()) {
            return '';
        }

        return implode("\n", array_slice(
            preg_split('/\r\n|\r|\n/', file_get_contents($path)),
            $reflection->getStartLine() - 1,
            $reflection->getStartLine() === $reflection->getEndLine() ? 1 : max($reflection->getEndLine() - $reflection->getStartLine(), 1) + 1,
        ));
    }

    public function getReflection(): ReflectionMethod
    {
        /**
         * \ReflectionMethod could've been used here, but for `\Closure::__invoke` it fails when constructed manually
         */
        return (new \ReflectionClass($this->className))->getMethod($this->name);
    }

    /**
     * @todo: Think if this method can actually return `null` or it should fail.
     */
    public function getAstNode(): ?ClassMethod
    {
        if (! $this->methodNode) {
            $className = class_basename($this->className);
            $methodReflection = $this->getReflection();

            $methodDoc = $methodReflection->getDocComment() ?: '';
            $lines = $methodReflection->getStartLine();

            $lines = str_repeat("\n", max($lines - 3 - substr_count($methodDoc, "\n"), 1));

            $partialClass = "<?php$lines class $className {\n".$methodDoc."\n".$this->getMethodCode()."\n}";

            $statements = $this->parser->parseContent($partialClass)->getStatements();
            $node = (new NodeFinder)
                ->findFirst(
                    $statements,
                    fn (Node $node) => $node instanceof Node\Stmt\ClassMethod && $node->name->name === $this->name,
                );

            $traverser = new NodeTraverser;

            $traverser->addVisitor(new class($this->getClassReflector()->getNameContext()) extends NameResolver
            {
                public function __construct($nameContext)
                {
                    parent::__construct();
                    $this->nameContext = $nameContext;
                }

                public function beforeTraverse(array $nodes): ?array
                {
                    return null;
                }
            });
            $traverser->addVisitor(new PhpDocResolver(
                new FileNameResolver($this->getClassReflector()->getNameContext()),
            ));

            $traverser->traverse([$node]);

            $this->methodNode = $node;
        }

        return $this->methodNode;
    }

    public function getClassReflector(): ClassReflector
    {
        return ClassReflector::make($this->getReflection()->class);
    }
}
