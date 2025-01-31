<?php

namespace Dedoc\Scramble\Reflections;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Infer\Reflector\MethodReflector;
use Dedoc\Scramble\Support\IndexBuilders\Bag;
use Dedoc\Scramble\Support\IndexBuilders\RequestParametersBuilder;
use Dedoc\Scramble\Support\RouteResponseTypeRetriever;
use Dedoc\Scramble\Support\Type\FunctionType;
use Illuminate\Routing\Route;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use ReflectionClass;
use ReflectionMethod;

class ReflectionRoute
{
    public ?FunctionType $methodType = null;

    private ?PhpDocNode $phpDoc = null;

    private ?ClassMethod $methodNode = null;

    public readonly Bag $requestParametersFromCalls;

    public readonly Infer\Extensions\IndexBuildingBroker $indexBuildingBroker;

    public function __construct(
        public readonly Route $route,
    ) {
        $this->requestParametersFromCalls = new Bag;
        $this->indexBuildingBroker = app(Infer\Extensions\IndexBuildingBroker::class);
    }

    public function isControllerAction(): bool
    {
        return is_string($this->route->getAction('uses'));
    }

    public function getControllerClass(): ?string
    {
        return $this->isControllerAction()
            ? explode('@', $this->route->getAction('uses'))[0]
            : null;
    }

    public function getControllerMethod(): ?string
    {
        return $this->isControllerAction()
            ? explode('@', $this->route->getAction('uses'))[1]
            : null;
    }

    public function parsePhpDoc(): PhpDocNode
    {
        if ($this->phpDoc) {
            return $this->phpDoc;
        }

        if (! $this->getControllerMethodAstNode()) {
            return new PhpDocNode([]);
        }

        $this->phpDoc = $this->getControllerMethodAstNode()->getAttribute('parsedPhpDoc') ?: new PhpDocNode([]);

        return $this->phpDoc;
    }

    public function getControllerMethodAstNode(): ?ClassMethod
    {
        if ($this->methodNode || ! $this->isControllerAction() || ! $this->getReflectionMethod()) {
            return $this->methodNode;
        }

        return $this->methodNode = MethodReflector::make(...explode('@', $this->route->getAction('uses')))
            ->getAstNode();
    }

    public function getReflectionMethod(): ?ReflectionMethod
    {
        if (! $this->isControllerAction()) {
            return null;
        }

        if (! method_exists($this->getControllerClass(), $this->getControllerMethod())) {
            return null;
        }

        return (new ReflectionClass($this->getControllerClass()))
            ->getMethod($this->getControllerMethod());
    }

    public function getReturnType()
    {
        return (new RouteResponseTypeRetriever($this))->getResponseType();
    }

    /**
     * @todo Maybe better name is needed as this method performs method analysis, indexes building, etc.
     */
    public function getMethodType(): ?FunctionType
    {
        if (! $this->isControllerAction() || ! $this->getReflectionMethod()) {
            return null;
        }

        if (! $this->methodType) {
            $def = $this->infer->analyzeClass($this->getReflectionMethod()->getDeclaringClass()->getName());

            /*
             * Here the final resolution of the method types may happen.
             */
            $this->methodType = $def->getMethodDefinition($this->getControllerMethod(), indexBuilders: [
                new RequestParametersBuilder($this->requestParametersFromCalls, $this->typeTransformer),
                ...$this->indexBuildingBroker->indexBuilders,
            ])->type;
        }

        return $this->methodType;
    }
}
