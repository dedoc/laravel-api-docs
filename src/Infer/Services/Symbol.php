<?php

namespace Dedoc\Scramble\Infer\Services;

use PhpParser\Node;

class Symbol
{
    public function __construct(
        public readonly string $type, // 'function', 'class', 'interface'
        public readonly string $name,
        public readonly string $location,
        public readonly ?string $extends = null,
        public readonly array $implements = [],
        public readonly array $uses = [], // traits (?)
    ) {}

    public function filterNodes(Node $node)
    {
        if ($this->type === 'class') {
            return $node instanceof Node\Stmt\Class_
                && $node->name->toString() === $this->name;
        }

        if ($this->type === 'function') {
            return $node instanceof Node\Stmt\Function_
                && $node->name->toString() === $this->name;
        }

        return false;
    }
}
