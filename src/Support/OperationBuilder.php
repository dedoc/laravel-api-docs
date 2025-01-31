<?php

namespace Dedoc\Scramble\Support;

use Dedoc\Scramble\Contexts\OperationTransformerContext;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Reflections\ReflectionRoute;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\TypeTransformer;

/** @internal */
class OperationBuilder
{
    /** @var class-string<OperationExtension> */
    private array $extensionsClasses;

    public function __construct(array $extensionsClasses = [])
    {
        $this->extensionsClasses = $extensionsClasses;
    }

    public function build(ReflectionRoute $reflectionRoute, OpenApi $openApi, GeneratorConfig $config, TypeTransformer $typeTransformer)
    {
        $operation = new Operation('get');

        $operationTransformerContext = new OperationTransformerContext(
            $reflectionRoute->route,
            $reflectionRoute,
            $openApi,
            $config,
        );

        $routeInfo = new RouteInfo($route, $this->infer, $typeTransformer);

        foreach ($config->operationTransformers->all() as $operationTransformer) {
            $instance = is_callable($operationTransformer)
                ? $operationTransformer
                : ContainerUtils::makeContextable($operationTransformer, [
                    OpenApi::class => $openApi,
                    GeneratorConfig::class => $config,
                    TypeTransformer::class => $typeTransformer,
                ]);

            if (is_callable($instance)) {
                $instance($operation, $context);

                continue;
            }

            $instance->handle($operation, $context);
        }


        foreach ($this->extensionsClasses as $extensionClass) {
            $extension = app()->make($extensionClass, [
                'openApi' => $openApi,
                'config' => $config,
                'openApiTransformer' => $typeTransformer,
            ]);

            $extension->handle($operation, $routeInfo);
        }

        return $operation;
    }
}
