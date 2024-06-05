<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts;

use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\Type;

interface TypeBasedRule
{
    public function shouldHandle(Type $rule): bool;

    public function handle(OpenApiType $previousType, Type $rule): OpenApiType;
}
