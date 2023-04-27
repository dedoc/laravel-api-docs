<?php

namespace Dedoc\Scramble\Infer\Handler;

use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\ObjectType;
use PhpParser\Node;

class ClassHandler implements CreatesScope
{
    public function createScope(Scope $scope, Node $node): Scope
    {
        return $scope->createChildScope(clone $scope->context);
    }

    public function shouldHandle($node)
    {
        return $node instanceof Node\Stmt\Class_;
    }

    public function enter(Node\Stmt\Class_ $node, Scope $scope)
    {
        $scope->context->setClass(
            $classType = new ObjectType($node->name ? $scope->resolveName($node->name->toString()) : 'anonymous@class'),
        );

        if ($node->extends) {
            $classType->setParent(
                new ObjectType($scope->resolveName($node->extends->toString())),
            );
        }

        if (str_contains($classType->name, 'Edge')) {
//        dd($node);

        }


        $scope->index->registerClassType($scope->resolveName($node->name->toString()), $classType);

        $scope->setType($node, $classType);
    }
}
