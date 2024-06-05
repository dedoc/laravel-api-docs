<?php

use Dedoc\Scramble\Support\InferExtensions\ArrayKeysReturnTypeExtension;

it('infers string array keys return type from literal string keys', function () {
    $type = getStatementType('array_keys(["foo" => 1, "bar" => 2])', [new ArrayKeysReturnTypeExtension]);

    expect($type->toString())->toBe('list{string(foo), string(bar)}');
});

it('infers all array keys return type from non-string keys', function () {
    $type = getStatementType('array_keys([1, "foo", "bar" => 42])', [new ArrayKeysReturnTypeExtension]);

    expect($type->toString())->toBe('list{int(0), int(1), string(bar)}');
});
