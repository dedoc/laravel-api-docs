<?php

namespace Dedoc\Scramble\Infer\Definition;

use Dedoc\Scramble\Infer\Analyzer\MethodAnalyzer;
use Dedoc\Scramble\Infer\Reflector\ClassReflector;
use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\UnknownType;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;

class ClassDefinition
{
    public function __construct(
        // FQ name
        public string $name,
        /** @var TemplateType[] $templateTypes */
        public array $templateTypes = [],
        /** @var array<string, ClassPropertyDefinition> $properties */
        public array $properties = [],
        /** @var array<string, FunctionLikeDefinition> $methods */
        public array $methods = [],
        public ?string $parentFqn = null,
    ) {}

    public function isInstanceOf(string $className)
    {
        return is_a($this->name, $className, true);
    }

    public function isChildOf(string $className)
    {
        return $this->isInstanceOf($className) && $this->name !== $className;
    }

    public function hasMethodDefinition(string $name): bool
    {
        return array_key_exists($name, $this->methods);
    }

    public function getMethodDefinitionWithoutAnalysis(string $name)
    {
        if (! array_key_exists($name, $this->methods)) {
            return null;
        }

        return $this->methods[$name];
    }

    public function getMethodDefiningClassName(string $name, Index $index)
    {
        $lastLookedUpClassName = $this->name;
        while ($lastLookedUpClassDefinition = $index->getClassDefinition($lastLookedUpClassName)) {
            if ($methodDefinition = $lastLookedUpClassDefinition->getMethodDefinitionWithoutAnalysis($name)) {
                return $methodDefinition->definingClassName;
            }

            if ($lastLookedUpClassDefinition->parentFqn) {
                $lastLookedUpClassName = $lastLookedUpClassDefinition->parentFqn;

                continue;
            }

            break;
        }

        return $lastLookedUpClassName;
    }

    public function getMethodDefinition(string $name, Scope $scope = new GlobalScope, array $indexBuilders = [])
    {
        if (! array_key_exists($name, $this->methods)) {
            return null;
        }

        $methodDefinition = $this->methods[$name];

        if (! $methodDefinition->isFullyAnalyzed()) {
            $this->methods[$name] = (new MethodAnalyzer(
                $scope->index,
                $this
            ))->analyze($methodDefinition, $indexBuilders);
        }

        $methodScope = new Scope(
            $scope->index,
            new NodeTypesResolver,
            new ScopeContext($this, $methodDefinition),
            new FileNameResolver(
                class_exists($this->name)
                    ? ClassReflector::make($this->name)->getNameContext()
                    : tap(new NameContext(new Throwing), fn (NameContext $nc) => $nc->startNamespace()),
            ),
        );

        (new ReferenceTypeResolver($scope->index))
            ->resolveFunctionReturnReferences($methodScope, $this->methods[$name]->type);

        foreach ($this->methods[$name]->type->exceptions as $i => $exceptionType) {
            if (ReferenceTypeResolver::hasResolvableReferences($exceptionType)) {
                $this->methods[$name]->type->exceptions[$i] = (new ReferenceTypeResolver($scope->index))
                    ->resolve($methodScope, $exceptionType)
                    ->mergeAttributes($exceptionType->attributes());
            }
        }

        return $this->methods[$name];
    }

    public function getPropertyDefinition($name)
    {
        return $this->properties[$name] ?? null;
    }

    public function hasPropertyDefinition(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    public function getMethodCallType(string $name, ?ObjectType $calledOn = null)
    {
        $methodDefinition = $this->methods[$name] ?? null;

        if (! $methodDefinition) {
            return new UnknownType("Cannot get type of calling method [$name] on object [$this->name]");
        }

        $type = $this->getMethodDefinition($name)->type;

        if (! $calledOn instanceof Generic) {
            return $type->getReturnType();
        }

        return $this->replaceTemplateInType($type, $calledOn->templateTypesMap)->getReturnType();
    }

    private function replaceTemplateInType(Type $type, array $templateTypesMap)
    {
        $type = clone $type;

        foreach ($templateTypesMap as $templateName => $templateValue) {
            (new TypeWalker)->replace(
                $type,
                fn ($t) => $t instanceof TemplateType && $t->name === $templateName ? $templateValue : null
            );
        }

        return $type;
    }
}
