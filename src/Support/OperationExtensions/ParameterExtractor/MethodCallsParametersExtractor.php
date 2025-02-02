<?php

namespace Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor;

use Dedoc\Scramble\Data\Parameter;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;

class MethodCallsParametersExtractor implements ParameterExtractor
{
    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $extractedParameters = new ParametersExtractionResult(
            parameters: array_values($routeInfo->requestParametersFromCalls->data),
        );

        $previouslyExtractedParameters = collect($parameterExtractionResults)->flatMap->parameters->keyBy('name');

        $extractedParameters->parameters = collect($extractedParameters->parameters)
            ->filter(fn (Parameter $p) => ! $previouslyExtractedParameters->has($p->name))
            ->values()
            ->all();

        /*
         * Possible improvements here: using defaults when merging results, etc.
         */

        return [...$parameterExtractionResults, $extractedParameters];
    }
}
