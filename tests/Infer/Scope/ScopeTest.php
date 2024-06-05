<?php

use Dedoc\Scramble\Infer\Analyzer\ClassAnalyzer;

function getStatementTypeForScopeTest(string $statement, array $extensions = [])
{
    return analyzeFile('<?php', $extensions)->getExpressionType($statement);
}

it('infers property fetch nodes types', function ($code, $expectedTypeString) {
    expect(getStatementTypeForScopeTest($code)->toString())->toBe($expectedTypeString);
})->with([
    ['$foo->bar', 'unknown'],
    ['$foo->bar->{"baz"}', 'unknown'],
]);

it('infers ternary expressions nodes types', function ($code, $expectedTypeString) {
    expect(getStatementTypeForScopeTest($code)->toString())->toBe($expectedTypeString);
})->with([
    ['unknown() ? 1 : null', 'int(1)|null'],
    ['unknown() ? 1 : 1', 'int(1)'],
    ['unknown() ?: 1', 'unknown|int(1)'],
    ['(int) unknown() ?: 1', 'int|int(1)'],
    ['1 ?: 1', 'int(1)'],
    ['unknown() ? 1 : unknown()', 'int(1)|unknown'],
    ['unknown() ? unknown() : unknown()', 'unknown'],
    ['unknown() ?: unknown()', 'unknown'],
    ['unknown() ?: true ?: 1', 'unknown|boolean(true)|int(1)'],
    ['unknown() ?: unknown() ?: unknown()', 'unknown'],
]);

it('infers static property fetch nodes types', function ($code, $expectedTypeString) {
    expect(getStatementType($code)->toString())->toBe($expectedTypeString);
})->with([
    ['parent::$bar', 'unknown'],
]);

it('infers concat string type', function ($code, $expectedTypeString) {
    expect(getStatementTypeForScopeTest($code)->toString())->toBe($expectedTypeString);
})->with([
    ['"a"."b"."c"', 'string(string(a), string(b), string(c))'],
]);
it('infers concat string type with unknowns', function ($code, $expectedTypeString) {
    expect(getStatementTypeForScopeTest($code)->toString())->toBe($expectedTypeString);
})->with([
    ['"a"."b".auth()->user()->id', 'string(string(a), string(b), unknown)'],
]);

it('analyzes call type of param properly', function () {
    $foo = app(ClassAnalyzer::class)
        ->analyze(ScopeTest_Foo::class)
        ->getMethodDefinition('foo');

    expect($foo->type->getReturnType()->toString())->toBe('int(42)');
});
class ScopeTest_Foo
{
    public function foo(ScopeTest_Bar $bar)
    {
        return $bar->getAnswer();
    }
}
class ScopeTest_Bar
{
    public function getAnswer()
    {
        return 42;
    }
}

it('infers match node type', function ($code, $expectedTypeString) {
    expect(getStatementTypeForScopeTest($code)->toString())->toBe($expectedTypeString);
})->with([
    [<<<'EOD'
match (unknown()) {
    42 => 1,
    default => null,
}
EOD, 'int(1)|null'],
]);
