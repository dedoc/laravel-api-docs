<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\DeepParametersMerger;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class RequestBodyExtension extends OperationExtension
{
    const HTTP_METHODS_WITHOUT_REQUEST_BODY = ['get', 'delete', 'head'];

    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $description = Str::of($routeInfo->phpDoc()->getAttribute('description'));

        /*
         * Making sure to analyze the route.
         * @todo rename the method
         */
        $routeInfo->getMethodType();

        $rulesResults = collect();

        try {
            $rulesResults = collect($this->extractParameters($operation, $routeInfo));
        } catch (Throwable $exception) {
            if (Scramble::shouldThrowOnError()) {
                throw $exception;
            }
            $description = $description->append('⚠️Cannot generate request documentation: '.$exception->getMessage());
        }

        $operation
            ->summary(Str::of($routeInfo->phpDoc()->getAttribute('summary'))->rtrim('.'))
            ->description($description);

        $allParams = $rulesResults->flatMap->parameters->unique('name')->values()->all();

        $mediaType = $this->getMediaType($operation, $routeInfo, $allParams);

        if (empty($allParams)) {
            return;
        }

        if (in_array($operation->method, static::HTTP_METHODS_WITHOUT_REQUEST_BODY)) {
            $operation->addParameters(
                $this->convertDotNamedParamsToComplexStructures($allParams)
            );

            return;
        }

        [$queryParams, $bodyParams] = collect($allParams)
            ->partition(fn (Parameter $p) => $p->getAttribute('isInQuery'))
            ->map->toArray();

        $operation->addParameters($this->convertDotNamedParamsToComplexStructures($queryParams));

        if (! $bodyParams) {
            return;
        }

        [$schemaResults, $schemalessResults] = $rulesResults->partition('schemaName');
        $schemalessResults = collect([$this->mergeSchemalessRulesResults($schemalessResults->values())]);

        $schemas = $schemaResults->merge($schemalessResults)
            ->filter(fn (ParametersExtractionResult $r) => count($r->parameters) || $r->schemaName)
            ->map(function (ParametersExtractionResult $r) use ($queryParams) {
                $qpNames = collect($queryParams)->keyBy('name');

                $r->parameters = collect($r->parameters)->filter(fn ($p) => ! $qpNames->has($p->name))->values()->all();

                return $r;
            })
            ->values()
            ->map($this->makeSchemaFromResults(...));

        if ($schemas->isEmpty()) {
            return;
        }

        $schema = $this->makeComposedRequestBodySchema($schemas);
        if (! $schema instanceof Reference) {
            $schema = Schema::fromType($schema);
        }

        $operation->addRequestBodyObject(
            RequestBodyObject::make()
                ->setContent($mediaType, $schema)
                ->required($this->isSchemaRequired($schema))
        );
    }

    protected function isSchemaRequired(Reference|Schema $schema): bool
    {
        $schema = $schema instanceof Reference
            ? $schema->resolve()
            : $schema;

        $type = $schema instanceof Schema ? $schema->type : $schema;

        if ($type instanceof ObjectType) {
            return count($type->required) > 0;
        }

        return false;
    }

    protected function makeSchemaFromResults(ParametersExtractionResult $result): Type
    {
        $requestBodySchema = Schema::createFromParameters(
            $this->convertDotNamedParamsToComplexStructures($result->parameters),
        );

        if (! $result->schemaName) {
            return $requestBodySchema->type;
        }

        $components = $this->openApiTransformer->getComponents();
        if (! $components->hasSchema($result->schemaName)) {
            $requestBodySchema->type->setDescription($result->description ?: '');

            $components->addSchema($result->schemaName, $requestBodySchema);
        }

        return new Reference('schemas', $result->schemaName, $components);
    }

    protected function makeComposedRequestBodySchema(Collection $schemas)
    {
        if ($schemas->count() === 1) {
            return $schemas->first();
        }

        return (new AllOf)->setItems($schemas->all());
    }

    protected function mergeSchemalessRulesResults(Collection $schemalessResults): ParametersExtractionResult
    {
        return new ParametersExtractionResult(
            parameters: $this->convertDotNamedParamsToComplexStructures($schemalessResults->values()->flatMap->parameters->unique('name')->values()->all()),
        );
    }

    protected function convertDotNamedParamsToComplexStructures($params)
    {
        return (new DeepParametersMerger(collect($params)))->handle();
    }

    protected function getMediaType(Operation $operation, RouteInfo $routeInfo, array $bodyParams): string
    {
        if (
            ($mediaTags = $routeInfo->phpDoc()->getTagsByName('@requestMediaType'))
            && ($mediaType = trim(Arr::first($mediaTags)?->value?->value))
        ) {
            return $mediaType;
        }

        $jsonMediaType = 'application/json';

        if ($operation->method === 'get') {
            return $jsonMediaType;
        }

        return $this->hasBinary($bodyParams) ? 'multipart/form-data' : $jsonMediaType;
    }

    protected function hasBinary($bodyParams): bool
    {
        return collect($bodyParams)->contains(function (Parameter $parameter) {
            // @todo: Use OpenApi document tree walker when ready
            $parameterString = json_encode($parameter->toArray());

            return Str::contains($parameterString, '"contentMediaType":"application\/octet-stream"');
        });
    }

    private function extractParameters(Operation $operation, RouteInfo $routeInfo)
    {
        $result = [];
        foreach ($this->config->parametersExtractors->all() as $extractorClass) {
            $extractor = $this->buildContextfulExtractor($extractorClass, [
                TypeTransformer::class => $this->openApiTransformer,
                Operation::class => $operation,
            ]);

            $result = $extractor->handle($routeInfo, $result);
        }

        return $result;
    }

    /**
     * @template T of ParameterExtractor
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function buildContextfulExtractor(string $class, array $contextfulBindings): ParameterExtractor
    {
        $reflectionClass = new ReflectionClass($class);

        $parameters = $reflectionClass->getConstructor()?->getParameters() ?? [];

        $contextfulArguments = collect($parameters)
            ->mapWithKeys(function (ReflectionParameter $p) use ($contextfulBindings) {
                $parameterClass = $p->getType() instanceof ReflectionNamedType
                    ? $p->getType()->getName()
                    : null;

                return $parameterClass && isset($contextfulBindings[$parameterClass]) ? [
                    $p->name => $contextfulBindings[$parameterClass],
                ] : [];
            })
            ->all();

        return app()->make($class, $contextfulArguments);
    }
}
