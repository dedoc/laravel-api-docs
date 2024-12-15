<?php

namespace Dedoc\Scramble\Support\Type;

use Dedoc\Scramble\Infer\Definition\FunctionLikeDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\ExtensionsBroker;
use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Scope\Scope;

class ObjectType extends AbstractType
{
    public function __construct(
        public string $name,
    ) {}

    public function isInstanceOf(string $className)
    {
        return is_a($this->name, $className, true);
    }

    public function isSame(Type $type)
    {
        return false;
    }

    public function getPropertyType(string $propertyName, Scope $scope = new GlobalScope): Type
    {
        if ($propertyType = app(ExtensionsBroker::class)->getPropertyType(new PropertyFetchEvent(
            instance: $this,
            name: $propertyName,
            scope: $scope,
        ))) {
            return $propertyType;
        }

        $definition = $scope->index->getClassDefinition($this->name);

        if (! $propertyDefinition = $definition?->getPropertyDefinition($propertyName)) {
            return new UnknownType("Cannot get a property type [$propertyName] on type [{$this->name}]");
        }

        return $propertyDefinition->type ?: $propertyDefinition->defaultType;
    }

    public function getMethodDefinition(string $methodName, Scope $scope = new GlobalScope): ?FunctionLikeDefinition
    {
        $classDefinition = $scope->index->getClassDefinition($this->name);

        return $classDefinition?->getMethodDefinition($methodName, $scope);
    }

    public function getMethodReturnType(string $methodName, array $arguments = [], Scope $scope = new GlobalScope): Type
    {
        $classDefinition = $scope->index->getClassDefinition($this->name);

        if ($returnType = app(ExtensionsBroker::class)->getMethodReturnType(new MethodCallEvent(
            instance: $this,
            name: $methodName,
            scope: $scope,
            arguments: $arguments,
            methodDefiningClassName: $definingClassName = $classDefinition ? $classDefinition->getMethodDefiningClassName($methodName, $scope->index) : $this->name,
        ))) {
            return $returnType;
        }

        /*
         * For now, when parent class is in `vendor`, we may do not know that certain definition exists.
         */
        if (! $methodDefinition = $this->getMethodDefinition($methodName)) {
            return new UnknownType("No method {$definingClassName}@{$methodName} definition found, it may be located in `vendor` which is not analyzed.");
        }

        $returnType = $methodDefinition->type->getReturnType();

        // Here templates should be replaced for generics and arguments should be taken into account.
        return $returnType instanceof TemplateType && $returnType->is
            ? $returnType->is
            : $returnType;
    }

    public function accepts(Type $otherType): bool
    {
        if (! $otherType instanceof ObjectType) {
            return false;
        }

        return is_a($otherType->name, $this->name, true);
    }

    public function toString(): string
    {
        return $this->name;
    }
}
