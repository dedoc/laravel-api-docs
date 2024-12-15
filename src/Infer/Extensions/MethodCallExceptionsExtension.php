<?php

namespace Dedoc\Scramble\Infer\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

interface MethodCallExceptionsExtension extends InferExtension
{
    public function shouldHandle(ObjectType $type): bool;

    /**
     * @return array<Type>
     */
    public function getMethodCallExceptions(MethodCallEvent $event): array;
}
