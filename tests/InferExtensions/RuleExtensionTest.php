<?php

it('infers different rules types', function (string $statement, $expectedType) {
    //    $a = function ($values) {
    //        dd(func_get_args());
    //    };
    //
    //    $a(["foo", "bar"], ...["a"]);

    $type = getStatementType($statement);

    expect($type->toString())->toBe($expectedType);
})->with([
    ['Illuminate\Validation\Rule::in(...["values" => ["foo", "bar"]])', 'Illuminate\Validation\Rules\In<list{string(foo), string(bar)}>'],
    ['Illuminate\Validation\Rule::in(values: ["foo", "bar"])', 'Illuminate\Validation\Rules\In<list{string(foo), string(bar)}>'],
    ['Illuminate\Validation\Rule::in(["foo", "bar"])', 'Illuminate\Validation\Rules\In<list{string(foo), string(bar)}>'],
]);
