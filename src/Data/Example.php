<?php

namespace Dedoc\Scramble\Data;

use Dedoc\Scramble\Support\Generator\Example as OpenApiExample;
use Dedoc\Scramble\Support\Generator\MissingValue as OpenApiMissingValue;

class Example
{
    public function __construct(
        public mixed $value = new MissingValue,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $externalValue = null,
    ) {}

    public static function toOpenApiExample(mixed $example)
    {
        if ($example instanceof static) {
            return new OpenApiExample(
                value: $example->value instanceof MissingValue ? new OpenApiMissingValue : $example->value,
                summary: $example->summary,
                description: $example->description,
                externalValue: $example->externalValue,
            );
        }

        return $example;
    }
}
