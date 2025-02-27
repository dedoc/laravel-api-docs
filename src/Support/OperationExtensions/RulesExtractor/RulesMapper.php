<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rules\Enum;

class RulesMapper
{
    private TypeTransformer $openApiTransformer;

    public function __construct(TypeTransformer $openApiTransformer)
    {
        $this->openApiTransformer = $openApiTransformer;
    }

    public function string(Type $prevType)
    {
        return (new StringType)->addProperties($prevType);
    }

    public function bool(Type $prevType)
    {
        return (new BooleanType)->addProperties($prevType);
    }

    public function boolean(Type $_)
    {
        return $this->bool($_);
    }

    public function numeric(Type $prevType)
    {
        return (new NumberType)->addProperties($prevType);
    }

    public function decimal(Type $prevType)
    {
        return (new NumberType)->addProperties($prevType);
    }

    public function int(Type $prevType)
    {
        return (new IntegerType)->addProperties($prevType);
    }

    public function integer(Type $_)
    {
        return $this->int($_);
    }

    public function array(Type $_, $params)
    {
        if (count($params)) {
            $object = (new \Dedoc\Scramble\Support\Generator\Types\ObjectType)
                ->setRequired($params);

            foreach ($params as $param) {
                $object->addProperty($param, new UnknownType);
            }

            return $object;
        }

        return new ArrayType;
    }

    public function exists(Type $type, $params)
    {
        if (! $type instanceof UnknownType) {
            return $type;
        }

        if (Str::is(['id', '*_id'], $column = $params[1] ?? 'id')) {
            return $this->int($type);
        }

        return $type;
    }

    public function email(Type $type)
    {
        if ($type instanceof UnknownType) {
            $type = $this->string($type);
        }

        return $type->format('email');
    }

    public function uuid(Type $type)
    {
        if ($type instanceof UnknownType) {
            $type = $this->string($type);
        }

        return $type->format('uuid');
    }

    public function nullable(Type $type)
    {
        return $type->nullable(true);
    }

    public function required(Type $type)
    {
        $type->setAttribute('required', true);

        return $type;
    }

    public function min(Type $type, $params)
    {
        if (
            $type instanceof NumberType
            || $type instanceof ArrayType
            || $type instanceof StringType
        ) {
            $type->setMin((float) $params[0]);
        }

        return $type;
    }

    public function max(Type $type, $params)
    {
        if (
            $type instanceof NumberType
            || $type instanceof ArrayType
            || $type instanceof StringType
        ) {
            $type->setMax((float) $params[0]);
        }

        return $type;
    }

    public function size(Type $type, $params)
    {
        $type = $this->min($type, $params);

        return $this->max($type, $params);
    }

    public function in(Type $type, $params)
    {
        return $type->enum(
            collect($params)
                ->mapInto(Stringable::class)
                ->map(fn (Stringable $v) => (string) $v->trim('"')->replace('""', '"'))
                ->values()
                ->all()
        );
    }

    public function enum(Type $_, Enum $rule)
    {
        $getProtectedValue = function ($obj, $name) {
            $array = (array) $obj;
            $prefix = chr(0).'*'.chr(0);

            return $array[$prefix.$name];
        };

        $enumName = $getProtectedValue($rule, 'type');

        $objectType = new ObjectType($enumName);

        $except = method_exists(Enum::class, 'except') ? $getProtectedValue($rule, 'except') : [];
        $only = method_exists(Enum::class, 'only') ? $getProtectedValue($rule, 'only') : [];

        if ($except || $only) {
            $cases = collect($enumName::cases())
                ->reject(fn ($case) => in_array($case, $except))
                ->filter(fn ($case) => ! $only || in_array($case, $only));

            if (! isset($cases->first()?->value)) {
                return new UnknownType("$enumName enum doesnt have values (only/except context)");
            }

            return $this->openApiTransformer->transform(Union::wrap(
                $cases->map(fn ($c) => TypeHelper::createTypeFromValue($c->value))->all()
            ));
        }

        return $this->openApiTransformer->transform($objectType);
    }

    public function image(Type $type)
    {
        return $this->file($type);
    }

    public function file(Type $type)
    {
        if ($type instanceof UnknownType) {
            $type = $this->string($type);
        }

        return $type->contentMediaType('application/octet-stream')->format('binary');
    }

    public function url(Type $type)
    {
        if ($type instanceof UnknownType) {
            $type = $this->string($type);
        }

        return $type->format('uri');
    }

    public function date(Type $type, $params)
    {
        if ($type instanceof UnknownType) {
            $type = $this->string($type);
        }

        if ($params[0] ?? '' === 'Y-m-d') {
            return $type->format('date');
        }

        return $type->format('date-time');
    }

    public function date_format(Type $type, $params)
    {
        return $this->date($type, $params);
    }
}
