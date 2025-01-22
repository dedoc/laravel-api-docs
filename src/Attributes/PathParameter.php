<?php

namespace Dedoc\Scramble\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_ALL)]
class PathParameter extends Parameter
{
    public readonly bool $required;

    public function __construct(
        string $name,
        ?string $description = null,
        ?bool $required = null,
        $deprecated = false,
        ?string $type = null,
        bool $infer = true,
        mixed $default = new MissingValue,
        mixed $example = new MissingValue,
        array $examples = [],
    ) {
        parent::__construct('path', $name, $description, $required, $deprecated, $type, $infer, $default, $example, $examples);
    }
}
