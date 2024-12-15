<?php

namespace Dedoc\Scramble\PhpDoc;

use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

class AbstractPhpDocTypeVisitor implements PhpDocTypeVisitor
{
    public function enter(TypeNode|Node $type): void {}

    public function leave(TypeNode|Node $type): void {}
}
