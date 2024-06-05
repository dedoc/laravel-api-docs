<?php

namespace Dedoc\Scramble\Infer\Scope;

use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\Type;
use PhpParser\Node;

class ScopeTypeResolver
{
    public function __construct(private Scope $scope)
    {
    }

    public function getType(Node $node): ?Type
    {
        $type = $this->scope->getType($node);

        if (! $type) {
            return null;
        }

        return (new ReferenceTypeResolver($this->scope->index))
            ->resolve($this->scope, $type)
            ->mergeAttributes($type->attributes());
    }
}
