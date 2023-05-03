<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * This extension is responsible for adding exceptions to the method return type
 * that may happen when an app navigates to the route.
 */
class ErrorResponsesExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        if (! $methodType = $routeInfo->getMethodType()) {
            return;
        }

        $this->attachNotFoundException($operation, $methodType);
        $this->attachAuthorizationException($routeInfo, $methodType);
        $this->attachCustomRequestExceptions($methodType);
    }

    private function attachNotFoundException(Operation $operation, FunctionType $methodType)
    {
        $hasModelParams = collect($operation->parameters)
            ->contains(function (Parameter $parameter) {
                return $parameter->in === 'path'
                    && $parameter->schema->type->getAttribute('isModelId') === true;
            });

        if (! $hasModelParams) {
            return;
        }

        $methodType->exceptions = [
            ...$methodType->exceptions,
            new ObjectType(ModelNotFoundException::class),
        ];
    }

    private function attachAuthorizationException(RouteInfo $routeInfo, FunctionType $methodType)
    {
        if (! collect($routeInfo->route->gatherMiddleware())->contains(fn ($m) => is_string($m) && Str::startsWith($m, 'can:'))) {
            return;
        }

        if (collect($methodType->exceptions)->contains(fn (Type $e) => $e->isInstanceOf(AuthorizationException::class))) {
            return;
        }

        $methodType->exceptions = [
            ...$methodType->exceptions,
            new ObjectType(AuthorizationException::class),
        ];
    }

    private function attachCustomRequestExceptions(FunctionType $methodType)
    {
        if (! $formRequest = collect($methodType->arguments)->first(fn (Type $arg) => $arg->isInstanceOf(FormRequest::class))) {
            return;
        }

        $formRequest = $formRequest instanceof ObjectType
            ? $formRequest
            : ($formRequest instanceof TemplateType ? $formRequest->is : null);

        if (! $formRequest) {
            return;
        }

        $methodType->exceptions = [
            ...$methodType->exceptions,
            new ObjectType(ValidationException::class),
        ];

        $formRequest = $this->infer->analyzeClass($formRequest->name);

        $authorizeReturnType = $formRequest->getMethodCallType('authorize');
        if (
            (! $authorizeReturnType instanceof LiteralBooleanType)
            || $authorizeReturnType->value !== true
        ) {
            $methodType->exceptions = [
                ...$methodType->exceptions,
                new ObjectType(AuthorizationException::class),
            ];
        }
    }
}
