<?php

namespace Dedoc\Scramble\Data;

use Dedoc\Scramble\Support\Generator\Parameter as OpenApiParameter;
use Dedoc\Scramble\Support\Generator\Types\Type as Schema;

class Parameter
{
    public ?bool $required;

    public function __construct(
        public string $name,
        public ?string $in = null,
        public ?string $description = null,
        ?bool $required = null,
        public bool $deprecated = false,
        public ?Schema $schema = null,
        public ?string $type = null,
        public ?string $style = null,
        public ?string $explode = null,
        public ?bool $allowReserved = null,
        public ?array $content = null,
        public mixed $default = new MissingValue,
        public mixed $example = new MissingValue,
        public array $examples = [],
        public ?ParameterMeta $meta = null,
    ) {
        $this->required = $required !== null ? $required : $this->in === 'path';
    }

    public function toOpenApi(): OpenApiParameter
    {
        $base = (new OpenApiParameter($this->name, $this->in ?: 'query'))
            ->setSchema(\Dedoc\Scramble\Support\Generator\Schema::fromType(
                $this->schema
            ))
            ->required($this->required)
            ->setExplode($this->explode)
            ->description($this->description)
            ->setStyle($this->style)
            ->setDeprecated($this->deprecated)
            ->example($this->example);

        return $base;
    }
}
