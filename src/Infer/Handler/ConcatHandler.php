<?php

namespace Dedoc\Scramble\Infer\Handler;

use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\ConcatenatedStringType;
use Dedoc\Scramble\Support\Type\TypeHelper;
use PhpParser\Node;

class ConcatHandler
{
    public function shouldHandle($node)
    {
        return $node instanceof Node\Expr\BinaryOp\Concat;
    }

    public function leave(Node\Expr\BinaryOp\Concat $node, Scope $scope)
    {
        $scope->setType(
            $node,
            new ConcatenatedStringType(TypeHelper::flattenStringConcatTypes([
                $scope->getType($node->left),
                $scope->getType($node->right),
            ])),
        );
    }
}
