<?php

namespace Dedoc\Scramble\Support\Generator;

use Dedoc\Scramble\Data\MissingValue;

class Example
{
    public function __construct(
        public mixed $value = new MissingValue,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $externalValue = null,
    ) {}

    public function toArray()
    {
        return array_filter(
            ['value' => $this->value],
            fn ($v) => ! $v instanceof MissingValue,
        ) + array_filter([
            'summary' => $this->summary,
            'description' => $this->description,
            'externalValue' => $this->externalValue,
        ]);
    }
}
