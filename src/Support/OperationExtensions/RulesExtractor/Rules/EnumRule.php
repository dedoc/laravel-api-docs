<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules;

use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts\TypeBasedRule;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Validation\Rules\Enum;

class EnumRule implements TypeBasedRule
{
    public function __construct(protected TypeTransformer $openApiTransformer)
    {
    }

    public function shouldHandle(Type $rule): bool
    {
        return $rule->isInstanceOf(Enum::class);
    }

    public function handle(OpenApiType $previousType, Type $rule): OpenApiType
    {
        $enumName = $this->getEnumName($rule);

        return $this->openApiTransformer->transform(
            new ObjectType($enumName)
        );
    }

    protected function getEnumName(Type $rule)
    {
        $enumTypePropertyType = $rule->getPropertyType('type');

        if (! $enumTypePropertyType instanceof LiteralStringType) {
            throw new \RuntimeException('Unexpected enum value type');
        }

        return $enumTypePropertyType->value;
    }
}
