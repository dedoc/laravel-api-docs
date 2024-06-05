<?php

namespace Dedoc\Scramble\Support\Type\Reference;

class ConstFetchReferenceType extends AbstractReferenceType
{
    public function __construct(
        public string|StaticReference $callee,
        public string $constName,
    ) {
    }

    public function toString(): string
    {
        $callee = is_string($this->callee) ? $this->callee : $this->callee->toString();

        return "(#{$callee})::{$this->constName}";
    }

    public function dependencies(): array
    {
        return [];
    }
}
