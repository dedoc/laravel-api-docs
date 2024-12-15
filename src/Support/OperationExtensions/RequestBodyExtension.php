<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\DeepParametersMerger;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\FormRequestRulesExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RequestMethodCallsExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ValidateCallExtractor;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
            $rulesResults = collect($this->extractRouteRequestValidationRules($routeInfo, $routeInfo->methodNode()));
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

    protected function extractRouteRequestValidationRules(RouteInfo $routeInfo, $methodNode)
    {
        /*
         * These are the extractors that are getting types from the validation rules, so it is
         * certain that a property must have the extracted type.
         */
        $typeDefiningHandlers = [
            new FormRequestRulesExtractor($methodNode, $this->openApiTransformer),
            new ValidateCallExtractor($methodNode, $this->openApiTransformer),
        ];

        $validationRulesExtractedResults = collect($typeDefiningHandlers)
            ->filter(fn ($h) => $h->shouldHandle())
            ->map(fn ($h) => $h->extract($routeInfo))
            ->values()
            ->toArray();

        /*
         * This is the extractor that cannot re-define the incoming type but can add new properties.
         * Also, it is useful for additional details.
         */
        $detailsExtractor = new RequestMethodCallsExtractor;

        $methodCallsExtractedResults = $detailsExtractor->extract($routeInfo);

        return $this->mergeExtractedProperties($validationRulesExtractedResults, $methodCallsExtractedResults);
    }

    /**
     * @param  ParametersExtractionResult[]  $rulesExtractedResults
     */
    protected function mergeExtractedProperties(array $rulesExtractedResults, ParametersExtractionResult $methodCallsExtractedResult)
    {
        $rulesParameters = collect($rulesExtractedResults)->flatMap->parameters->keyBy('name');

        $methodCallsExtractedResult->parameters = collect($methodCallsExtractedResult->parameters)
            ->filter(fn (Parameter $p) => ! $rulesParameters->has($p->name))
            ->values()
            ->all();

        /*
         * Possible improvements here: using defaults when merging results, etc.
         */

        return [...$rulesExtractedResults, $methodCallsExtractedResult];
    }
}
