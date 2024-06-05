<?php

namespace Dedoc\Scramble\Support;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Infer\Reflector\MethodReflector;
use Dedoc\Scramble\Infer\Scope\ScopeTypeResolver;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\PhpDoc\PhpDocTypeHelper;
use Dedoc\Scramble\Support\IndexBuilders\Bag;
use Dedoc\Scramble\Support\IndexBuilders\RequestParametersBuilder;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeTraverser;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use ReflectionClass;
use ReflectionMethod;

class RouteInfo
{
    public Route $route;

    public ?FunctionType $methodType = null;

    private ?PhpDocNode $phpDoc = null;

    private ?ClassMethod $methodNode = null;

    private FileParser $parser;

    private Infer $infer;

    public readonly Bag $requestParametersFromCalls;

    public function __construct(Route $route, FileParser $fileParser, Infer $infer)
    {
        $this->route = $route;
        $this->parser = $fileParser;
        $this->infer = $infer;
        $this->requestParametersFromCalls = new Bag();
    }

    public function isClassBased(): bool
    {
        return is_string($this->route->getAction('uses'));
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
        /*
         * PHP Doc return type is considered only if the code return type is
         * unknown, or if there is a generic in PHP Doc type, that has some JsonResource in it.
         */
        $phpDocReturnType = ($phpDocType = $this->getDocReturnType()) ? PhpDocTypeHelper::toType($phpDocType) : null;
        $inferredReturnType = $this->getCodeReturnType();

        $phpDocReturnType?->setAttribute('fromPhpDoc', true);

        if (! $phpDocReturnType) {
            return $inferredReturnType;
        }

        if ($phpDocType->getAttribute('source') === 'response') {
            return $phpDocReturnType;
        }

        if ($inferredReturnType instanceof UnknownType) {
            return $phpDocReturnType;
        }

        if (
            $phpDocReturnType instanceof Generic
            && (new TypeWalker)->first($phpDocReturnType, fn (Type $t) => $t->isInstanceOf(JsonResource::class) || $t->isInstanceOf(Model::class))
        ) {
            return $phpDocReturnType;
        }

        $phpDocReturnTypeWeight = $phpDocReturnType ? $this->countKnownTypes($phpDocReturnType) : 0;
        $inferredReturnTypeWeight = $this->countKnownTypes($inferredReturnType);
        if ($phpDocReturnTypeWeight > $inferredReturnTypeWeight) {
            return $phpDocReturnType;
        }

        return $inferredReturnType;
    }

    private function countKnownTypes(Type $type)
    {
        $counterVisitor = new class
        {
            public int $count = 0;

            public function enter(Type $type)
            {
            }

            public function leave(Type $type)
            {
                if (
                    $type instanceof ObjectType
                    || $type instanceof StringType
                    || $type instanceof IntegerType
                    || $type instanceof FloatType
                    || $type instanceof BooleanType
                    || $type instanceof NullType
                    /*
                     * Give some weight for keyed array item so when comparing `array<mixed>` to `array{foo: unknown}`,
                     * the keyed array is preferred.
                     */
                    || $type instanceof ArrayItemType_ && is_string($type->key)
                ) {
                    $this->count++;
                }
            }
        };

        (new TypeTraverser([$counterVisitor]))->traverse($type);

        return $counterVisitor->count;
    }

    public function getDocReturnType()
    {
        if (! $this->phpDoc()) {
            return null;
        }

        if (($responseType = $this->phpDoc()->getReturnTagValues('@response')[0] ?? null) && optional($responseType)->type) {
            $responseType->type->setAttribute('source', 'response');

            return $responseType->type;
        }

        if (($returnType = $this->phpDoc()->getReturnTagValues()[0] ?? null) && optional($returnType)->type) {
            return $returnType->type;
        }

        return null;
    }

    public function getCodeReturnType()
    {
        if (! $this->getMethodType()) {
            return null;
        }

        return (new ObjectType($this->reflectionMethod()->getDeclaringClass()->getName()))
            ->getMethodReturnType($this->methodName());
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
                new RequestParametersBuilder($this->requestParametersFromCalls),
            ])->type;
        }

        return $this->methodType;
    }

    /**
     * Returns a scope type resolver which allows to get the type of any
     * node from the method body.
     *
     * @internal - not sure if this is here to stick
     */
    public function getMethodScopeTypeResolver(): ?ScopeTypeResolver
    {
        if (! $this->isClassBased() || ! $this->getMethodType()) {
            return null;
        }

        $methodScope = $this->infer
            ->analyzeClass($this->className())
            ->getMethodScope($this->methodName());

        return new ScopeTypeResolver($methodScope);
    }
}
