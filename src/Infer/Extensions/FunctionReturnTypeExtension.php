<?php

namespace Dedoc\Scramble\Infer\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\FunctionCallEvent;
use Dedoc\Scramble\Support\Type\Type;

interface FunctionReturnTypeExtension extends InferExtension
{
    public function shouldHandle(string $name): bool;

    public function getFunctionReturnType(FunctionCallEvent $event): ?Type;
}
