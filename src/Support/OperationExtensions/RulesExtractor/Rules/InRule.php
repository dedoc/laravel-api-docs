<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules;

use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts\StringBasedRule;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts\TypeBasedRule;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rules\In;

class InRule implements StringBasedRule, TypeBasedRule
{
    public function shouldHandle(string|Type $rule): bool
    {
        if (is_string($rule)) {
            return $rule === 'in';
        }

        return $rule->isInstanceOf(In::class);
    }

    public function handle(OpenApiType $previousType, string|Type $rule, array $parameters = []): OpenApiType
    {
        $allowedValues = $this->getNormalizedValues($rule, $parameters);

        return $previousType->enum(
            collect($allowedValues)
                ->mapInto(Stringable::class)
                ->map(fn (Stringable $v) => (string) $v->trim('"')->replace('""', '"'))
                ->values()
                ->all()
        );
    }

    private function getNormalizedValues(Type|string $rule, array $parameters)
    {
        if (is_string($rule)) {
            return $parameters;
        }

        $valueType = $rule->getPropertyType('value');
        if (! $valueType instanceof ArrayType) {
            throw new \RuntimeException('Invalid value type');
        }

        return collect($valueType->items)
            ->map(fn (ArrayItemType_ $itemType) => $itemType->value)
            ->filter(fn (Type $t) => $t instanceof LiteralStringType)
            ->map(fn (LiteralStringType $t) => $t->value)
            ->values()
            ->all();
    }
}
