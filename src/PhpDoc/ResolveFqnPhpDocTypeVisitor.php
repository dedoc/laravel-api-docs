<?php

namespace Dedoc\Scramble\PhpDoc;

use Dedoc\Scramble\Infer\Services\FileNameResolver;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

class ResolveFqnPhpDocTypeVisitor extends AbstractPhpDocTypeVisitor
{
    private FileNameResolver $nameResolver;

    public function __construct(FileNameResolver $nameResolver)
    {
        $this->nameResolver = $nameResolver;
    }

    public function enter(TypeNode|Node $type): void
    {
        if ($type instanceof IdentifierTypeNode) {
            $type->name = ($this->nameResolver)($type->name);
        }
    }
}
