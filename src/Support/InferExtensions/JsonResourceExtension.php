<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Helpers\JsonResourceHelper;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Reference\MethodCallReferenceType;
use Dedoc\Scramble\Support\Type\Reference\PropertyFetchReferenceType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\MissingValue;

class JsonResourceExtension implements MethodReturnTypeExtension, PropertyTypeExtension
{
    public function shouldHandle(ObjectType|string $type): bool
    {
        if (is_string($type)) {
            return is_a($type, JsonResource::class, true);
        }

        return $type->isInstanceOf(JsonResource::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        return match ($event->name) {
            'toArray' => $event->methodDefiningClassName === JsonResource::class
                ? $this->getModelMethodReturn($event->getInstance()->name, 'toArray', $event->arguments, $event->scope)
                : null,

            'response', 'toResponse' => new Generic(JsonResponse::class, [$event->getInstance(), new LiteralIntegerType(200), new ArrayType]),

            'whenLoaded' => count($event->arguments) === 1
                ? Union::wrap([
                    $this->getModelPropertyType(
                        $event->getDefinition(),
                        $event->getArg('attribute', 0)->value ?? '',
                        $event->scope
                    ),
                    new ObjectType(MissingValue::class),
                ])
                : Union::wrap([
                    $this->value($event->getArg('value', 1)),
                    $this->value($event->getArg('default', 2, new ObjectType(MissingValue::class))),
                ]),

            'when', 'unless', 'whenPivotLoaded' => Union::wrap([
                $this->value($event->getArg('value', 1)),
                $this->value($event->getArg('default', 2, new ObjectType(MissingValue::class))),
            ]),

            'merge' => new Generic(MergeValue::class, [
                new LiteralBooleanType(true),
                $this->value($event->getArg('value', 0)),
            ]),

            'mergeWhen', 'mergeUnless' => new Generic(MergeValue::class, [
                new BooleanType,
                $this->value($event->getArg('value', 1)),
            ]),

            'whenHas', 'whenAppended' => count($event->arguments) === 1
                ? Union::wrap([$this->getModelPropertyType(
                    $event->getDefinition(),
                    $event->getArg('attribute', 0)->value ?? '',
                    $event->scope
                ), new ObjectType(MissingValue::class)])
                : Union::wrap([
                    ($valueType = $event->getArg('value', 1, new NullType)) instanceof NullType
                        ? $this->getModelPropertyType(
                            $event->getDefinition(),
                            $event->getArg('attribute', 0)->value ?? '',
                            $event->scope
                        )
                        : $this->value($valueType),
                    $this->value($event->getArg('default', 2, new ObjectType(MissingValue::class))),
                ]),

            'whenNotNull' => Union::wrap([
                $this->value($this->removeNullFromUnion($event->getArg('value', 0))),
                $this->value($event->getArg('default', 1, new ObjectType(MissingValue::class))),
            ]),

            'whenNull' => Union::wrap([
                new NullType,
                $this->value($event->getArg('default', 1, new ObjectType(MissingValue::class))),
            ]),

            'whenAggregated' => count($event->arguments) <= 3
                ? Union::wrap([
                    match ($event->getArg('aggregate', 2)?->value ?? '') {
                        'count' => new IntegerType,
                        'avg', 'sum' => new FloatType,
                        default => new StringType,
                    },
                    $this->value($event->getArg('default', 4, new ObjectType(MissingValue::class))),
                ])
                : Union::wrap([
                    $this->value($event->getArg('value', 3)),
                    $this->value($event->getArg('default', 4, new ObjectType(MissingValue::class))),
                ]),

            'whenExistsLoaded' => count($event->arguments) === 1
                ? Union::wrap([new BooleanType, new ObjectType(MissingValue::class)])
                : Union::wrap([
                    $this->value($event->getArg('value', 1)),
                    $this->value($event->getArg('default', 2, new ObjectType(MissingValue::class))),
                ]),

            'whenPivotLoadedAs' => Union::wrap([
                $this->value($event->getArg('value', 2)),
                $this->value($event->getArg('default', 3, new ObjectType(MissingValue::class))),
            ]),

            'hasPivotLoaded', 'hasPivotLoadedAs' => new BooleanType,

            'whenCounted' => count($event->arguments) === 1
                ? Union::wrap([new IntegerType, new ObjectType(MissingValue::class)])
                : Union::wrap([
                    $this->value($event->getArg('value', 1)),
                    $this->value($event->getArg('default', 2, new ObjectType(MissingValue::class))),
                ]),

            'attributes' => $this->getAttributesMethodReturnType($event),

            default => ! $event->getDefinition() || $event->getDefinition()->hasMethodDefinition($event->name)
                ? null
                : $this->proxyMethodCallToModel($event),
        };
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        return match ($event->name) {
            'resource' => JsonResourceHelper::modelType($event->getDefinition(), $event->scope),
            default => ! $event->getDefinition() || $event->getDefinition()->hasPropertyDefinition($event->name)
                ? null
                : $this->getModelPropertyType($event->getDefinition(), $event->name, $event->scope),
        };
    }

    private function getModelPropertyType(ClassDefinition $jsonResourceDefinition, string $name, Scope $scope)
    {
        return ReferenceTypeResolver::getInstance()->resolve(
            $scope,
            new PropertyFetchReferenceType(
                JsonResourceHelper::modelType($jsonResourceDefinition, $scope),
                $name,
            ),
        );
    }

    private function proxyMethodCallToModel(MethodCallEvent $event)
    {
        return $this->getModelMethodReturn($event->getInstance()->name, $event->name, $event->arguments, $event->scope);
    }

    private function getModelMethodReturn(string $resourceClassName, string $methodName, array $arguments, Scope $scope)
    {
        $modelType = JsonResourceHelper::modelType($scope->index->getClassDefinition($resourceClassName), $scope);

        return ReferenceTypeResolver::getInstance()->resolve(
            $scope,
            new MethodCallReferenceType($modelType, $methodName, arguments: $arguments),
        );
    }

    private function value(Type $type)
    {
        return $type instanceof FunctionType ? $type->getReturnType() : $type;
    }

    private function removeNullFromUnion(Type $type)
    {
        $type = Union::wrap(
            ReferenceTypeResolver::getInstance()->resolve(new GlobalScope, $type)
        );

        $types = $type instanceof Union ? $type->types : [$type];

        return Union::wrap(
            collect($types)->filter(fn ($t) => ! $t instanceof NullType)->values()->all()
        );
    }

    private function getAttributesMethodReturnType(MethodCallEvent $event)
    {
        $argument = $event->getArg('attributes', 0);

        $value = $argument instanceof KeyedArrayType
            ? collect($argument->items)->map(fn (ArrayItemType_ $t) => $t->value instanceof LiteralStringType ? $t->value->value : null)->filter()->values()->all()
            : ($argument instanceof LiteralStringType ? $argument->value : []);

        $modelToArrayReturn = $this->getModelMethodReturn($event->getInstance()->name, 'toArray', $event->arguments, $event->scope);

        if (! $modelToArrayReturn instanceof KeyedArrayType) {
            return new Generic(MergeValue::class, [
                new LiteralBooleanType(true),
                new KeyedArrayType([]),
            ]);
        }

        return new Generic(MergeValue::class, [
            new LiteralBooleanType(true),
            new KeyedArrayType(
                collect($modelToArrayReturn->items)
                    ->filter(fn (ArrayItemType_ $t) => in_array($t->key, $value))
                    ->values()
                    ->all()
            ),
        ]);
    }
}
