<?php

namespace Dedoc\Scramble\Data;

use Dedoc\Scramble\PhpDoc\PhpDocTypeHelper;
use Dedoc\Scramble\Support\Generator\Types\Type as Schema;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Helpers\ExamplesExtractor;
use Dedoc\Scramble\Support\Type\StringType;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class ParameterMeta
{
    public string $in;

    public string $description;

    public string $format;

    public bool $inQuery;

    public bool $isIgnored;

    /**
     * Parameters names with dot will be represented as objects. Setting `$isFlat` to `true` will preserve
     * dots in names and won't represent it as an object.
     */
    public bool $isFlat;

    public Schema $schema;

    public mixed $default;

    public mixed $example;

    public static function applyDataFromPhpDoc(
        self $meta,
        ?PhpDocNode $phpDoc,
        TypeTransformer $typeTransformer,
        bool $preferString = false
    ): self {
        if (! $phpDoc) {
            return $meta;
        }

        $description = (string) Str::of($phpDoc->getAttribute('summary') ?: '')
            ->append(' '.($phpDoc->getAttribute('description') ?: ''))
            ->trim();

        if ($description) {
            $meta->setDescription($description);
        }

        if (count($varTags = $phpDoc->getVarTagValues())) {
            $varTag = array_values($varTags)[0];

            $meta->setSchema($typeTransformer->transform($type = PhpDocTypeHelper::toType($varTag->type)));

            $preferString = $type instanceof StringType;
        }

        if ($default = ExamplesExtractor::make($phpDoc, '@default')->extract($preferString)) {
            $meta->setDefault($default[0]);
        }

        if ($examples = ExamplesExtractor::make($phpDoc)->extract($preferString)) {
            $meta->setExample($examples[0]);
        }

        if ($phpDoc->getTagsByName('@query')) {
            $meta->setInQuery(true);
        }

        if ($format = array_values($phpDoc->getTagsByName('@format'))[0]->value->value ?? null) {
            $meta->setFormat($format);
        }

        return $meta;
    }

    public function setIn(string $in): ParameterMeta
    {
        $this->in = $in;

        return $this;
    }

    public function setDescription(string $description): ParameterMeta
    {
        $this->description = $description;

        return $this;
    }

    public function setFormat(string $format): ParameterMeta
    {
        $this->format = $format;

        return $this;
    }

    public function setInQuery(bool $inQuery): ParameterMeta
    {
        $this->inQuery = $inQuery;

        return $this;
    }

    public function setIsIgnored(bool $isIgnored): ParameterMeta
    {
        $this->isIgnored = $isIgnored;

        return $this;
    }

    public function setIsFlat(bool $isFlat): ParameterMeta
    {
        $this->isFlat = $isFlat;

        return $this;
    }

    public function setSchema(Schema $schema): ParameterMeta
    {
        $this->schema = $schema;

        return $this;
    }

    public function setDefault(mixed $default): ParameterMeta
    {
        $this->default = $default;

        return $this;
    }

    public function setExample(mixed $example): ParameterMeta
    {
        $this->example = $example;

        return $this;
    }
}
