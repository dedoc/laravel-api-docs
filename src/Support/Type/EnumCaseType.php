<?php

namespace Dedoc\Scramble\Support\Type;

class EnumCaseType extends ObjectType
{
    public function __construct(
        string $name,
        public string $caseName,
    ) {
        parent::__construct($name);
    }

    public function isSame(Type $type)
    {
        return $type instanceof static && $type->toString() === $this->toString();
    }

    public function toString(): string
    {
        return "{$this->name}::{$this->caseName}";
    }
}
