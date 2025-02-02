<?php

namespace Dedoc\Scramble\Attributes;

use Attribute;
use Dedoc\Scramble\Data\MissingValue;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_ALL)]
class CookieParameter extends Parameter
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
        parent::__construct('cookie', $name, $description, $required, $deprecated, $type, $infer, $default, $example, $examples);
    }
}
