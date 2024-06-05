<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

class ValidateCallExtractor
{
    private ?Node\FunctionLike $handle;

    public function __construct(?Node\FunctionLike $handle)
    {
        $this->handle = $handle;
    }

    public function shouldHandle()
    {
        return (bool) $this->handle;
    }

    public function node(): ?ValidationNodesResult
    {
        $methodNode = $this->handle;

        // $request->validate, when $request is a Request instance
        /** @var Node\Expr\MethodCall $callToValidate */
        $callToValidate = (new NodeFinder())->findFirst(
            $methodNode,
            fn (Node $node) => $node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && is_a($this->getPossibleParamType($methodNode, $node->var), Request::class, true)
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'validate'
        );
        $validationRules = $callToValidate->args[0] ?? null;

        if (! $validationRules) {
            // $this->validate($request, $rules), rules are second param. First should be $request, but no way to check type. So relying on convention.
            $callToValidate = (new NodeFinder())->findFirst(
                $methodNode,
                fn (Node $node) => $node instanceof Node\Expr\MethodCall
                    && count($node->args) === 2
                    && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier && $node->name->name === 'validate'
                    && $node->args[0]->value instanceof Node\Expr\Variable
                    && is_a($this->getPossibleParamType($methodNode, $node->args[0]->value), Request::class, true)
                    && $node->name->name === 'validate'
            );
            $validationRules = $callToValidate->args[1] ?? null;
        }

        if (! $validationRules) {
            // Validator::make($request->...(), $rules), rules are second param. First should be $request, but no way to check type. So relying on convention.
            $callToValidate = (new NodeFinder())->findFirst(
                $methodNode,
                fn (Node $node) => $node instanceof Node\Expr\StaticCall
                    && count($node->args) === 2
                    && $node->class instanceof Node\Name && is_a($node->class->toString(), \Illuminate\Support\Facades\Validator::class, true)
                    && $node->name instanceof Node\Identifier && $node->name->name === 'make'
                    && $node->args[0]->value instanceof Node\Expr\MethodCall && is_a($this->getPossibleParamType($methodNode, $node->args[0]->value->var), Request::class, true)
            );
            $validationRules = $callToValidate->args[1] ?? null;
        }

        if (! $validationRules) {
            return null;
        }

        return new ValidationNodesResult(
            $validationRules instanceof Node\Arg ? $validationRules->value : $validationRules,
        );
    }

    public function extract(RouteInfo $routeInfo)
    {
        $methodNode = $this->handle;
        $validationRules = $this->node()->node ?? null;

        if ($validationRules) {
            $type = $routeInfo->getMethodScopeTypeResolver()->getType($validationRules);
            //                        dump([
            //                            $routeInfo->className().'@'.$routeInfo->methodName() => $type->toString(),
            //                        ]);
        }

        if ($validationRules) {
            $printer = new Standard();
            $validationRulesCode = $printer->prettyPrint([$validationRules]);

            $injectableParams = collect($methodNode->getParams())
                ->filter(fn (Node\Param $param) => isset($param->type->name))
                ->filter(fn (Node\Param $param) => ! class_exists($className = (string) $param->type) || ! is_a($className, Request::class, true))
                ->filter(fn (Node\Param $param) => isset($param->var->name) && is_string($param->var->name))
                ->mapWithKeys(function (Node\Param $param) {
                    try {
                        $type = (string) $param->type;
                        $primitives = [
                            'int' => 1,
                            'bool' => true,
                            'string' => '',
                            'float' => 1,
                        ];
                        $value = $primitives[$type] ?? app($type);

                        return [
                            $param->var->name => $value,
                        ];
                    } catch (\Throwable $e) {
                        return [];
                    }
                })
                ->all();

            try {
                extract($injectableParams);

                $rules = eval("\$request = request(); return $validationRulesCode;");
            } catch (\Throwable $exception) {
                throw $exception;
            }
        }

        return $rules ?? null;
    }

    private function getPossibleParamType(Node\Stmt\ClassMethod $methodNode, Node\Expr\Variable $node): ?string
    {
        $paramsMap = collect($methodNode->getParams())
            ->mapWithKeys(function (Node\Param $param) {
                if (! isset($param->type->name)) {
                    return [];
                }

                try {
                    return [
                        $param->var->name => $param->type->name,
                    ];
                } catch (\Throwable $exception) {
                    throw $exception;
                }
            })
            ->toArray();

        return $paramsMap[$node->name] ?? null;
    }
}
