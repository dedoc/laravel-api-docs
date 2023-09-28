<?php

namespace Dedoc\Scramble\Support\Type\Reference;

use Dedoc\Scramble\Support\Type\Reference\Dependency\MethodDependency;
use Dedoc\Scramble\Support\Type\Reference\Dependency\PropertyDependency;
use Dedoc\Scramble\Support\Type\Type;

class StaticPropertyFetchReferenceType extends AbstractReferenceType
{
    public function __construct(
        public string $callee,
        public string $propertyName,
    ) {
    }

    public function toString(): string
    {
        return "(#{$this->callee})::\${$this->propertyName}";
    }

    public function dependencies(): array
    {
        return [
            new PropertyDependency($this->callee, $this->propertyName),
        ];
    }
}