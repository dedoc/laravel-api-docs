<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Support\ResponseExtractor\ModelInfo;
use Dedoc\Scramble\Support\Type\AbstractType;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ModelExtension implements MethodReturnTypeExtension, PropertyTypeExtension
{
    private static $cache;

    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(Model::class);
    }

    public function hasProperty(ObjectType $type, string $name)
    {
        $info = $this->getModelInfo($type);

        return $info->get('attributes')->has($name) || $info->get('relations')->has($name);
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        if (! $this->hasProperty($event->getInstance(), $event->getName())) {
            return null;
        }

        $info = $this->getModelInfo($event->getInstance());

        if ($attribute = $info->get('attributes')->get($event->getName())) {
            $baseType = $this->getAttributeTypeFromEloquentCasts($attribute['cast'] ?? '')
                ?? $this->getAttributeTypeFromDbColumnType($attribute['type'], $attribute['driver'])
                ?? new UnknownType("Virtual attribute ({$attribute['name']}) type inference not supported.");

            if ($attribute['nullable']) {
                return Union::wrap([$baseType, new NullType]);
            }

            return $baseType;
        }

        if ($relation = $info->get('relations')->get($event->getName())) {
            return $this->getRelationType($relation);
        }

        throw new \LogicException('Should not happen');
    }

    /**
     * MySQL/MariaDB decimal is mapped to a string by PDO.
     * Floating point numbers and decimals are all mapped to strings when using the pgsql driver.
     */
    private function getAttributeTypeFromDbColumnType(?string $columnType, ?string $dbDriverName): ?AbstractType
    {
        if ($columnType === null) {
            return null;
        }

        $typeName = str($columnType)
            ->before(' ') // strip modifiers from a type name such as `bigint unsigned`
            ->before('(') // strip the length from a type name such as `tinyint(4)`
            ->toString();

        if (in_array($typeName, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'])) {
            return new IntegerType;
        }

        if ($dbDriverName === 'sqlite' && in_array($typeName, ['float', 'double', 'decimal'])) {
            return new FloatType;
        }

        if (in_array($dbDriverName, ['mysql', 'mariadb']) && in_array($typeName, ['float', 'double'])) {
            return new FloatType;
        }

        return new StringType;
    }

    /**
     * @todo Add support for custom castables.
     */
    private function getAttributeTypeFromEloquentCasts(string $cast): ?AbstractType
    {
        if ($cast && enum_exists($cast)) {
            return new ObjectType($cast);
        }

        $castAsType = Str::before($cast, ':');
        $castAsParameters = str($cast)->after("{$castAsType}:")->explode(',');

        if (Str::startsWith($castAsType, 'encrypted:')) {
            $castAsType = $castAsParameters->first(); // array, collection, json, object
        }

        return match ($castAsType) {
            'array', 'json' => new ArrayType,
            'real', 'float', 'double' => new FloatType,
            'int', 'integer', 'timestamp' => new IntegerType,
            'bool', 'boolean' => new BooleanType,
            'string', 'decimal' => new StringType,
            'object' => new ObjectType('\stdClass'),
            'collection' => new ObjectType(Collection::class),
            'Illuminate\Database\Eloquent\Casts\AsEnumCollection' => new Generic(Collection::class, [
                new TemplateType($castAsParameters->first()),
            ]),
            'date', 'datetime', 'custom_datetime' => new ObjectType(Carbon::class),
            'immutable_date', 'immutable_datetime', 'immutable_custom_datetime' => new ObjectType(CarbonImmutable::class),
            default => null,
        };
    }

    private function getRelationType(array $relation)
    {
        if ($isManyRelation = Str::contains($relation['type'], 'Many')) {
            return new Generic(
                \Illuminate\Database\Eloquent\Collection::class,
                [new ObjectType($relation['related'])]
            );
        }

        return new ObjectType($relation['related']);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        if ($event->getName() !== 'toArray') {
            return null;
        }

        if ($this->getRealToArrayMethodDefinitionClassName($event) !== Model::class) {
            return null;
        }

        $info = $this->getModelInfo($event->getInstance());

        /** @var Model $instance */
        $instance = $info->get('instance');

        $arrayableAttributesTypes = $info->get('attributes', collect())
            ->when($instance->getVisible(), fn ($c, $visible) => $c->only($visible))
            ->when($instance->getHidden(), fn ($c, $visible) => $c->except($visible))
            ->filter(fn ($attr) => $attr['appended'] !== false)
            ->map(function ($_, $name) use ($event) {
                $propertyType = $event->getInstance()->getPropertyType($name);

                (new TypeWalker)->replace($propertyType, function (Type $t) {
                    return ($t->isInstanceOf(Carbon::class) || $t->isInstanceOf(CarbonImmutable::class))
                        ? tap(new StringType, fn ($t) => $t->setAttribute('format', 'date-time'))
                        : null;
                });

                return $propertyType;
            });

        $arrayableRelationsTypes = $info->get('relations', collect())
            ->only($this->getProtectedValue($instance, 'with'))
            ->when($instance->getVisible(), fn ($c, $visible) => $c->only($visible))
            ->when($instance->getHidden(), fn ($c, $visible) => $c->except($visible))
            ->map(function ($_, $name) use ($event) {
                return $event->getInstance()->getPropertyType($name);
            });

        return new KeyedArrayType([
            ...$arrayableAttributesTypes->map(fn ($type, $name) => new ArrayItemType_($name, $type))->values()->all(),
            ...$arrayableRelationsTypes->map(fn ($type, $name) => new ArrayItemType_($name, $type, isOptional: true))->values()->all(),
        ]);
    }

    private function getModelInfo(ObjectType $type)
    {
        return static::$cache[$type->name] ??= (new ModelInfo($type->name))->handle();
    }

    private function getProtectedValue($obj, $name)
    {
        $array = (array) $obj;
        $prefix = chr(0).'*'.chr(0);

        return $array[$prefix.$name];
    }

    /**
     * Due to vendor classes being not analyzed for now, we may have a situation when defining class name in event is not
     * truly represents the location of the method. But we want to make sure to get it right.
     */
    private function getRealToArrayMethodDefinitionClassName(MethodCallEvent $event)
    {
        $className = $event->methodDefiningClassName ?: $event->getInstance()->name;

        try {
            $reflectionMethod = new \ReflectionMethod($className, 'toArray');

            return $reflectionMethod->getDeclaringClass()->getName();
        } catch (Throwable) {
        }

        return $event->methodDefiningClassName;
    }
}
