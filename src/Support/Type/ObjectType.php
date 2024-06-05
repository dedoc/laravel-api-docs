<?php

namespace Dedoc\Scramble\Support\Type;

use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Infer\Definition\FunctionLikeDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Scope\Scope;

class ObjectType extends AbstractType
{
    public function __construct(
        public string $name,
    ) {
    }

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
        if ($propertyType = Context::getInstance()->extensionsBroker->getPropertyType(new PropertyFetchEvent(
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

    public function getMethodReturnType(string $methodName, array $arguments = [], Scope $scope = new GlobalScope): ?Type
    {
        if ($returnType = Context::getInstance()->extensionsBroker->getMethodReturnType(new MethodCallEvent(
            instance: $this,
            name: $methodName,
            scope: $scope,
            arguments: $arguments,
        ))) {
            return $returnType;
        }

        if (! $methodDefinition = $this->getMethodDefinition($methodName)) {
            return null;
        }

        $returnType = $methodDefinition->type->getReturnType();

        // Here templates should be replaced for generics and arguments should be taken into account.
        return $returnType instanceof TemplateType && $returnType->is
            ? $returnType->is
            : $returnType;
    }

    public function toString(): string
    {
        return $this->name;
    }
}
