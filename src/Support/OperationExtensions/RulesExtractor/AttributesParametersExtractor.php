<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Attributes\Example;
use Dedoc\Scramble\Attributes\MissingValue;
use Dedoc\Scramble\Attributes\Parameter as ParameterAttribute;
use Dedoc\Scramble\PhpDoc\PhpDocTypeHelper;
use Dedoc\Scramble\Support\Generator\MissingExample;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\PhpDoc;
use Dedoc\Scramble\Support\RouteInfo;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionAttribute;
use ReflectionClass;

class AttributesParametersExtractor implements RulesExtractor
{
    /**
     * @param ParametersExtractionResult[] $automaticallyExtractedParameters
     */
    public function __construct(
        private array $automaticallyExtractedParameters,
        private TypeTransformer $openApiTransformer,
    )
    {
    }

    public function shouldHandle(): bool
    {
        return true;
    }

    public function extract(RouteInfo $routeInfo): ParametersExtractionResult
    {
        if (! $reflectionMethod = $routeInfo->reflectionMethod()) {
            return new ParametersExtractionResult([]);
        }

        $parameters = collect($reflectionMethod->getAttributes(ParameterAttribute::class, ReflectionAttribute::IS_INSTANCEOF))
            ->values()
            ->map(fn (ReflectionAttribute $ra) => $this->createParameter($ra->newInstance(), $ra->getArguments()))
            ->all();

        return new ParametersExtractionResult($parameters);
    }

    private function createParameter(ParameterAttribute $attribute, array $attributeArguments): Parameter
    {
        $attributeParameter = $this->createParameterFromAttribute($attribute);

        if (! $attribute->infer) {
            return $attributeParameter;
        }

        if (! $inferredParameter = $this->getParameterFromAutomaticallyInferred($attribute->in, $attribute->name)) {
            return $attributeParameter;
        }

        $parameter = clone $inferredParameter;

        $namedAttributes = $this->createNamedAttributes($attribute::class, $attributeArguments);

        foreach ($namedAttributes as $name => $attrValue) {
            if ($name === 'in' || $name === 'name') {
                continue;
            }

            if ($name === 'default') {
                $parameter->schema->type->default = $attrValue;
            }

            if ($name === 'type') {
                $parameter->schema->type = $attributeParameter->schema->type;
            }

            if ($name === 'deprecated') {
                $parameter->deprecated = $attributeParameter->deprecated;
            }

            if ($name === 'required') {
                $parameter->required = $attributeParameter->required;
            }

            if ($name === 'example') {
                $parameter->example = $attributeParameter->example;
            }

            if ($name === 'examples') {
                $parameter->examples = $attributeParameter->examples;
            }
        }

        return $parameter;
    }

    private function createParameterFromAttribute(ParameterAttribute $attribute): Parameter
    {
        $default = $attribute->default instanceof MissingValue ? new MissingExample : $attribute->default;
        $type = $attribute->type ? $this->openApiTransformer->transform(
            PhpDocTypeHelper::toType(
                PhpDoc::parse("/** @return $attribute->type */")->getReturnTagValues()[0]->type ?? new IdentifierTypeNode('mixed')
            )
        ) : new MixedType();

        $parameter = Parameter::make($attribute->name, $attribute->in)
            ->description($attribute->description ?: '')
            ->setSchema(Schema::fromType(
                $type->default($default)
            ))
            ->required($attribute->required);

        $parameter->deprecated = $attribute->deprecated;

        if (! $attribute->example instanceof MissingValue) {
            $parameter->example = $attribute->example;
        }

        if ($attribute->examples) {
            $parameter->examples = array_map(
                fn (Example $e) => Example::toOpenApiExample($e),
                $attribute->examples,
            );
        }

        return $parameter;
    }

    private function getParameterFromAutomaticallyInferred(string $in, string $name): ?Parameter
    {
        foreach ($this->automaticallyExtractedParameters as $automaticallyExtractedParameters) {
            foreach ($automaticallyExtractedParameters->parameters as $parameter) {
                if (
                    $parameter->in === $in
                    && $parameter->name === $name
                ) {
                    return $parameter;
                }
            }
        }

        return null;
    }

    private function createNamedAttributes(string $class, array $attributeArguments): array
    {
        $reflectionClass = new ReflectionClass($class);

        if (! $reflectionConstructor = $reflectionClass->getConstructor()) {
            return $attributeArguments;
        }

        $constructorParameters = $reflectionConstructor->getParameters();

        return collect($attributeArguments)
            ->mapWithKeys(function ($value, $key) use ($constructorParameters) {
                $name = is_string($key) ? $key : $constructorParameters[$key]->getName();

                return [$name => $value];
            })
            ->all();
    }
}
