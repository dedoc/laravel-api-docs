<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class RuleExtension implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === Rule::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        return rescue(fn () => match ($event->name) {
            'in' => new Generic(In::class, [
                $event->getArg('values', 0),
            ]),
            'enum' => new Generic(Enum::class, [
                $event->getArg('type', 0),
            ]),
            'unique' => new Generic(Unique::class, [
                $event->getArg('table', 0),
                $event->getArg('column', 1, new LiteralStringType('NULL')),
            ]),
            'exists' => new Generic(Exists::class, [
                $event->getArg('table', 0),
                $event->getArg('column', 1, new LiteralStringType('NULL')),
            ]),
            default => null,
        });
    }
}
