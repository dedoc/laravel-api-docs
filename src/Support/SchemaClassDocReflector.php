<?php

namespace Dedoc\Scramble\Support;

use Dedoc\Scramble\Infer\Reflector\ClassReflector;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\PhpDoc\PhpDocTypeWalker;
use Dedoc\Scramble\PhpDoc\ResolveFqnPhpDocTypeVisitor;
use PhpParser\NameContext;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocChildNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

class SchemaClassDocReflector
{
    public function __construct(public readonly PhpDocNode $phpDoc) {}

    public function getTagValue(string $tagName)
    {
        return array_values($this->phpDoc->getTagsByName($tagName))[0]?->value ?? null;
    }

    public function getSchemaName(string $default = ''): ?string
    {
        return explode("\n", $this->phpDoc->getTagsByName('@schemaName')[0]->value->value ?? $default)[0];
    }

    public function getDescription(): string
    {
        return trim(implode("\n", array_map(
            static function (PhpDocChildNode $child): string {
                $s = (string) $child;
                if ($child instanceof PhpDocTagNode) {
                    $s = explode("\n", $s, 2)[1] ?? '';
                }

                return $s === '' ? '' : ' '.$s;
            },
            array_filter($this->phpDoc->children, fn ($n) => $n instanceof PhpDocTextNode || $n instanceof PhpDocTagNode)
        )));
    }

    public static function createFromDocString(string $phpDocString, ?string $definingClassName = null)
    {
        $parsedDoc = PhpDoc::parse($phpDocString ?: '/** */');

        if ($definingClassName) {
            $classReflector = ClassReflector::make($definingClassName);

            $nameContext = $classReflector->getNameContext();

            $parsedDoc = static::replaceClassNamesInDoc($parsedDoc, $nameContext);
        }

        return new self($parsedDoc);
    }

    public static function createFromClassName(string $className)
    {
        $classReflector = ClassReflector::make($className);

        $nameContext = $classReflector->getNameContext();
        $reflection = $classReflector->getReflection();

        $parsedDoc = static::replaceClassNamesInDoc(PhpDoc::parse($reflection->getDocComment() ?: '/** */'), $nameContext);

        return new self($parsedDoc);
    }

    private static function replaceClassNamesInDoc(PhpDocNode $docNode, NameContext $nameContext): PhpDocNode
    {
        $tagValues = [
            ...$docNode->getReturnTagValues(),
            ...$docNode->getVarTagValues(),
            ...$docNode->getThrowsTagValues(),
        ];

        foreach ($tagValues as $tagValue) {
            if (! $tagValue->type) {
                continue;
            }
            PhpDocTypeWalker::traverse($tagValue->type, [
                new ResolveFqnPhpDocTypeVisitor(new FileNameResolver($nameContext)),
            ]);
        }

        return $docNode;
    }
}
