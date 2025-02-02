<?php

namespace Dedoc\Scramble\Support\IndexBuilders;

use Dedoc\Scramble\Data\MissingValue;
use Dedoc\Scramble\Data\Parameter;
use Dedoc\Scramble\Data\ParameterMeta;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\Literal\LiteralFloatType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class RequestParametersBuilder implements IndexBuilder
{
    public function __construct(
        public readonly Bag $bag,
        private readonly TypeTransformer $typeTransformer
    ) {}

    public function afterAnalyzedNode(Scope $scope, Node $node): void
    {
        // @todo: Find more general approach to get a comment related to the node
        [$commentHolderNode, $methodCallNode] = match ($node::class) {
            Node\Stmt\Expression::class => [
                $node,
                $node->expr instanceof Node\Expr\Assign ? $node->expr->expr : $node->expr,
            ],
            Node\Arg::class => [$node, $node->value],
            Node\ArrayItem::class => [$node, $node->value],
            default => [null, null],
        };

        if (! $commentHolderNode) {
            return;
        }

        if (! $methodCallNode instanceof Node\Expr\MethodCall) {
            return;
        }

        $varType = $scope->getType($methodCallNode->var);

        if (! $varType->isInstanceOf(Request::class)) {
            return;
        }

        if (! $name = $this->getNameNodeValue($scope, $methodCallNode->name)) {
            return;
        }

        if (! ($parameterName = TypeHelper::getArgType($scope, $methodCallNode->args, ['key', 0])->value ?? null)) {
            return;
        }

        if ($this->shouldIgnoreParameter($commentHolderNode)) {
            return;
        }

        $meta = new ParameterMeta;

        [$parameterType, $parameterDefault] = match ($name) {
            'integer' => $this->makeIntegerParameter($scope, $methodCallNode),
            'float' => $this->makeFloatParameter($scope, $methodCallNode),
            'boolean' => $this->makeBooleanParameter($scope, $methodCallNode),
            'enum' => $this->makeEnumParameter($scope, $methodCallNode),
            'query' => $this->makeQueryParameter($scope, $methodCallNode, $meta),
            'string', 'str', 'input' => $this->makeStringParameter($scope, $methodCallNode),
            'get', 'post' => $this->makeFlatParameter($scope, $methodCallNode, $meta),
            default => [null, null],
        };

        if (! $parameterType) {
            return;
        }

        $parameter = new Parameter(
            name: $parameterName,
            schema: $this->typeTransformer->transform($parameterType),
            default: $parameterDefault ?? new MissingValue,
            meta: $meta,
        );

        ParameterMeta::applyDataFromPhpDoc(
            $meta,
            $node->getAttribute('parsedPhpDoc'),
            $this->typeTransformer,
            $parameterType instanceof StringType
        );

        $this->bag->set($parameterName, $parameter);
    }

    private function getNameNodeValue(Scope $scope, Node $nameNode)
    {
        if ($nameNode instanceof Node\Identifier) {
            return $nameNode->name;
        }

        $type = $scope->getType($nameNode);
        if (! $type instanceof LiteralStringType) {
            return null;
        }

        return $type->value;
    }

    private function makeIntegerParameter(Scope $scope, Node $node)
    {
        return [
            new IntegerType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1], new LiteralIntegerType(0))->value ?? null,
        ];
    }

    private function makeFloatParameter(Scope $scope, Node $node)
    {
        return [
            new FloatType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1], new LiteralFloatType(0))->value ?? null,
        ];
    }

    private function makeBooleanParameter(Scope $scope, Node $node)
    {
        return [
            new BooleanType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1], new LiteralBooleanType(false))->value ?? null,
        ];
    }

    private function makeStringParameter(Scope $scope, Node $node)
    {
        return [
            new StringType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1])->value ?? null,
        ];
    }

    private function makeFlatParameter(Scope $scope, Node $node, ParameterMeta $meta)
    {
        $meta->setIsFlat(true);

        return [
            new StringType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1])->value ?? null,
        ];
    }

    private function makeEnumParameter(Scope $scope, Node $node)
    {
        if (! $className = TypeHelper::getArgType($scope, $node->args, ['default', 1])->value ?? null) {
            return [null, null];
        }

        return [
            new ObjectType($className),
            null,
        ];
    }

    private function makeQueryParameter(Scope $scope, Node $node, ParameterMeta $meta)
    {
        $meta->setInQuery(true);

        return [
            new UnknownType,
            TypeHelper::getArgType($scope, $node->args, ['default', 1])->value ?? null,
        ];
    }

    private function shouldIgnoreParameter(NodeAbstract $node)
    {
        /** @var PhpDocNode|null $phpDoc */
        $phpDoc = $node->getAttribute('parsedPhpDoc');

        return (bool) $phpDoc?->getTagsByName('@ignoreParam');
    }
}
