<?php

namespace Dedoc\Scramble\Infer\Definition;

use Dedoc\Scramble\Infer\Contracts\FunctionLikeDefinition as FunctionLikeDefinitionContract;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeAttributes;

class FunctionLikeDefinition implements FunctionLikeDefinitionContract
{
    use TypeAttributes;

    public bool $isFullyAnalyzed = false;

    /**
     * @param  array<string, Type>  $argumentsDefaults  A map where the key is arg name and value is a default type.
     */
    public function __construct(
        public FunctionType $type,
        public array $sideEffects = [],
        public array $argumentsDefaults = [],
        public ?string $definingClassName = null,
        public bool $isStatic = false,
    ) {}

    public function getType(): FunctionType
    {
        return $this->type;
    }

    public function getData(): static
    {
        return $this;
    }

    public function isFullyAnalyzed(): bool
    {
        return $this->isFullyAnalyzed;
    }

    public function addArgumentDefault(string $paramName, Type $type): self
    {
        $this->argumentsDefaults[$paramName] = $type;

        return $this;
    }
}
