<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\Rules\Contracts;

use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;

/**
 * The contract for documenting validation rules used as strings.
 *
 * For example, in case when rule `in` is used like this, it is considered as string based.
 * ```php
 * $request->validate(['field' => 'in:foo,bar'])
 * ```
 *
 * `shouldHandle` will receive `'in'` as `$rule` argument.
 * `handle` will receive `'in'` as `$rule` and `['foo', 'bar']` as `$parameters`.
 */
interface StringBasedRule
{
    public function shouldHandle(string $rule): bool;

    public function handle(OpenApiType $previousType, string $rule, array $parameters): OpenApiType;
}
