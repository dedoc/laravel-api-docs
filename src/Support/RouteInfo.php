<?php

namespace Dedoc\Scramble\Support;

use Closure;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Infer\Reflector\MethodReflector;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\IndexBuilders\Bag;
use Dedoc\Scramble\Support\IndexBuilders\RequestParametersBuilder;
use Dedoc\Scramble\Support\Type\FunctionType;
use Illuminate\Routing\Route;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use ReflectionClass;
use ReflectionMethod;

class RouteInfo
{
    public ?FunctionType $methodType = null;

    private ?PhpDocNode $phpDoc = null;

    private ?ClassMethod $methodNode = null;

    public readonly Bag $requestParametersFromCalls;

    public readonly Infer\Extensions\IndexBuildingBroker $indexBuildingBroker;

    public function __construct(
        public readonly Route $route,
        private Infer $infer,
        private readonly TypeTransformer $typeTransformer
    ) {
        $this->requestParametersFromCalls = new Bag;
        $this->indexBuildingBroker = app(Infer\Extensions\IndexBuildingBroker::class);
    }

    public function isClassBased(): bool
    {
        return is_string($this->route->getAction('uses'));
    }

    public function isClosureBased(): bool
    {
        return $this->route->getAction('uses')(...) instanceof Closure;
    }

    public function className(): ?string
    {
        return $this->isClassBased()
            ? explode('@', $this->route->getAction('uses'))[0]
            : null;
    }

    public function methodName(): ?string
    {
        return $this->isClassBased()
            ? explode('@', $this->route->getAction('uses'))[1]
            : null;
    }

    public function phpDoc(): PhpDocNode
    {
        if ($this->phpDoc) {
            return $this->phpDoc;
        }

        if (! $this->methodNode()) {
            return new PhpDocNode([]);
        }

        $this->phpDoc = $this->methodNode()->getAttribute('parsedPhpDoc') ?: new PhpDocNode([]);

        return $this->phpDoc;
    }

    public function methodNode(): ?ClassMethod
    {
        if ($this->methodNode || ! $this->isClassBased() || ! $this->reflectionMethod()) {
            return $this->methodNode;
        }

        return $this->methodNode = MethodReflector::make(...explode('@', $this->route->getAction('uses')))
            ->getAstNode();
    }

    public function reflectionMethod(): ?ReflectionMethod
    {
        if (! $this->isClassBased()) {
            return null;
        }

        if (! method_exists($this->className(), $this->methodName())) {
            return null;
        }

        return (new ReflectionClass($this->className()))
            ->getMethod($this->methodName());
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
        if (! $this->isClassBased() || ! $this->reflectionMethod()) {
            return null;
        }

        if (! $this->methodType) {
            $def = $this->infer->analyzeClass($this->reflectionMethod()->getDeclaringClass()->getName());

            /*
             * Here the final resolution of the method types may happen.
             */
            $this->methodType = $def->getMethodDefinition($this->methodName(), indexBuilders: [
                new RequestParametersBuilder($this->requestParametersFromCalls, $this->typeTransformer),
                ...$this->indexBuildingBroker->indexBuilders,
            ])->type;
        }

        return $this->methodType;
    }
}
