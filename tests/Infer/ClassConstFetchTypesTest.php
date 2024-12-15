<?php

it(
    'infers class const fetch types',
    fn ($statement, $expectedType) => expect(getStatementType($statement)->toString())->toBe($expectedType),
)->with([
    ['$var::class', 'string'],
    ['(new SomeType)::class', 'string(SomeType)'],
    ['SomeType::class', 'string(SomeType)'],
    ['Enum_ClassConstFetchTypesTest::FOO', 'Enum_ClassConstFetchTypesTest::FOO'],
]);

enum Enum_ClassConstFetchTypesTest: string
{
    case FOO = 'foo';
    case BAR = 'bar';
}
