<?php

namespace Dedoc\Scramble\Support;

use Closure;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\TypeTransformer;

/** @internal */
class OperationBuilder
{
    public function __construct(
        /** @var list<class-string<OperationExtension>|(Closure(Operation, RouteInfo): void)> */
        private array $extensions = [],
    ) {
    }

    public function build(RouteInfo $routeInfo, OpenApi $openApi, GeneratorConfig $config, TypeTransformer $typeTransformer)
    {
        $operation = new Operation('get');

        foreach ($this->extensions as $extension) {
            if ($extension instanceof Closure) {
                $extension($operation, $routeInfo);

                continue;
            }

            $extension = app()->make($extension, [
                'openApi' => $openApi,
                'config' => $config,
                'openApiTransformer' => $typeTransformer,
            ]);

            $extension->handle($operation, $routeInfo);
        }

        return $operation;
    }
}
