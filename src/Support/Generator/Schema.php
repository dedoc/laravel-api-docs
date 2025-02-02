<?php

namespace Dedoc\Scramble\Support\Generator;

use Dedoc\Scramble\Data\Parameter as ParameterData;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Support\Collection;

class Schema
{
    public Type $type;

    private ?string $title = null;

    public static function fromType(Type $type)
    {
        $schema = new static;
        $schema->setType($type);

        return $schema;
    }

    private function setType(Type $type)
    {
        $this->type = $type;

        return $this;
    }

    public function toArray()
    {
        $typeArray = $this->type->toArray();

        if ($typeArray instanceof \stdClass) { // mixed
            $typeArray = [];
        }

        $result = array_merge($typeArray, array_filter([
            'title' => $this->title,
        ]));

        if (empty($result)) {
            return (object) [];
        }

        return $result;
    }

    /**
     * @param ParameterData[] $parameters
     * @return ObjectType
     */
    public static function createFromParameters(array $parameters)
    {
        $type = new ObjectType;

        collect($parameters)
            ->each(function (ParameterData $parameter) use ($type) {
                $paramType = $parameter->schema ?? new StringType;

                $paramType->setDescription($parameter->description ?: '');
                $paramType->example($parameter->example);

                $type->addProperty($parameter->name, $paramType);
            })->dd()
            ->tap(fn (Collection $params) => $type->setRequired(
                $params->where('required', true)->map->name->values()->all()
            ));

        return $type;
    }

    public function setTitle(?string $title): Schema
    {
        $this->title = $title;

        return $this;
    }
}
