<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules;

use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts\StringBasedRule;

class StringRule implements StringBasedRule
{
    public function shouldHandle(string $rule): bool
    {
        return $rule === 'string';
    }

    public function handle(OpenApiType $previousType, string $rule, array $parameters): OpenApiType
    {
        return new StringType();
    }
}
