<?php

namespace Dedoc\Scramble\OperationTransformers;

use Dedoc\Scramble\Contexts\OperationTransformerContext;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;

class ExtensionWrapperTransformer
{
    public function __construct(private string $extension)
    {
    }

    public function __invoke(Operation $operation, OperationTransformerContext $context)
    {
        $routeInfo = new RouteInfo($context->route, $context->reflectionRoute);

        $extensionInstance = new $this->extension(

        )
    }


    public function handle(Operation $operation, OperationTransformerContext $context)
    {
        $routeInfo = new RouteInfo($context->route, $this->infer, $this->typeTransformer);
    }
}
