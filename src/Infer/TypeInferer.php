<?php

namespace Dedoc\Scramble\Infer;

use Dedoc\Scramble\Infer\Extensions\ExpressionExceptionExtension;
use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Extensions\InferExtension;
use Dedoc\Scramble\Infer\Handler\ArrayHandler;
use Dedoc\Scramble\Infer\Handler\ArrayItemHandler;
use Dedoc\Scramble\Infer\Handler\AssignHandler;
use Dedoc\Scramble\Infer\Handler\ClassHandler;
use Dedoc\Scramble\Infer\Handler\ConcatHandler;
use Dedoc\Scramble\Infer\Handler\CreatesScope;
use Dedoc\Scramble\Infer\Handler\ExceptionInferringExtensions;
use Dedoc\Scramble\Infer\Handler\ExpressionTypeInferringExtensions;
use Dedoc\Scramble\Infer\Handler\FunctionLikeHandler;
use Dedoc\Scramble\Infer\Handler\PhpDocHandler;
use Dedoc\Scramble\Infer\Handler\PropertyHandler;
use Dedoc\Scramble\Infer\Handler\ReturnHandler;
use Dedoc\Scramble\Infer\Handler\ThrowHandler;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class TypeInferer extends NodeVisitorAbstract
{
    private array $handlers;

    /**
     * @param  InferExtension[]  $extensions
     */
    public function __construct(
        private Index $index,
        private FileNameResolver $nameResolver,
        private ?Scope $scope = null,
        array $extensions = [],
        array $handlers = [],
    ) {
        $this->handlers = [
            new FunctionLikeHandler(),
            new AssignHandler(),
            new ConcatHandler(),
            new ClassHandler(),
            new PropertyHandler(),
            new ArrayHandler(),
            new ArrayItemHandler(),
            new ReturnHandler(),
            new ThrowHandler(),
            new ExpressionTypeInferringExtensions(array_values(array_filter(
                $extensions,
                fn ($ext) => $ext instanceof ExpressionTypeInferExtension,
            ))),
            new ExceptionInferringExtensions(array_values(array_filter(
                $extensions,
                fn ($ext) => $ext instanceof ExpressionExceptionExtension,
            ))),
            new PhpDocHandler(),
            ...$handlers,
        ];
    }

    public function enterNode(Node $node)
    {
        $scope = $this->getOrCreateScope();

        foreach ($this->handlers as $handler) {
            if (! $handler->shouldHandle($node)) {
                continue;
            }

            if ($handler instanceof CreatesScope) {
                $this->scope = $handler->createScope($scope, $node);
            }

            if (method_exists($handler, 'enter')) {
                $handler->enter($node, $this->scope);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        $shouldLeaveScope = false;

        foreach ($this->handlers as $handler) {
            if (! $handler->shouldHandle($node)) {
                continue;
            }

            if (method_exists($handler, 'leave')) {
                $handler->leave($node, $this->scope);
            }

            if ($handler instanceof CreatesScope) {
                $shouldLeaveScope = true;
            }
        }

        if ($shouldLeaveScope) {
            $this->scope = $this->scope->parentScope;
        }

        return null;
    }

    private function getOrCreateScope()
    {
        if (! isset($this->scope)) {
            $this->scope = new Scope(
                $this->index,
                new NodeTypesResolver,
                new ScopeContext,
                $this->nameResolver,
            );
        }

        return $this->scope;
    }
}
