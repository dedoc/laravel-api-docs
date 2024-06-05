<?php

namespace Dedoc\Scramble\Support\Type;

class ConcatenatedStringType extends StringType
{
    /**
     * @param  Type[]  $parts  Types of parts being concatenated.
     */
    public function __construct(public array $parts)
    {
    }

    public function nodes(): array
    {
        return ['parts'];
    }

    public function isSame(Type $type)
    {
        return $this === $type;
    }

    public function toString(): string
    {
        return parent::toString().'('.implode(', ', array_map(fn ($p) => $p->toString(), $this->parts)).')';
    }
}
