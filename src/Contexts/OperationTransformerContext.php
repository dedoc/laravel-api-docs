<?php

namespace Dedoc\Scramble\Contexts;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Reflections\ReflectionRoute;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Routing\Route;

class OperationTransformerContext
{
    public function __construct(
        public readonly Route $route,
        public readonly ReflectionRoute $reflectionRoute,
        public readonly OpenApi $document,
        public readonly GeneratorConfig $config,
    )
    {
    }
}
