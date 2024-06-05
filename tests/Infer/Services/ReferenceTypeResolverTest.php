<?php

use Dedoc\Scramble\Infer\Analyzer\ClassAnalyzer;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;

beforeEach(function () {
    $this->index = app(Index::class);

    $this->classAnalyzer = new ClassAnalyzer($this->index);

    $this->resolver = new ReferenceTypeResolver($this->index);
});

/*
 * Late static binding
 */

/*
 * Class' `class` const fetch
 */
it('infers static keywords const fetches on parent class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfClassFetch', 'string(Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo)'],
    ['staticClassFetch', 'string(Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo)'],
]);

it('infers static keywords const fetches on child class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfClassFetch', 'string(Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo)'],
    ['staticClassFetch', 'string(Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar)'],
    ['parentClassFetch', 'string(Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo)'],
]);

/*
 * Class const fetch
 */
it('infers static keywords some consts fetches on parent class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfConstFetch', 'int(42)'],
    ['staticConstFetch', 'int(42)'],
]);

it('infers static keywords some consts fetches on child class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfConstFetch', 'int(42)'],
    ['staticConstFetch', 'int(21)'],
    ['parentConstFetch', 'int(42)'],
]);

/*
 * New calls
 */
it('infers new calls on parent class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['newSelfCall', 'Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo'],
    ['newStaticCall', 'Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo'],
]);

it('infers new calls on child class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['newSelfCall', 'Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo'],
    ['newStaticCall', 'Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar<string(foo)>'],
    ['newParentCall', 'Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo'],
]);

/*
 * Static method calls (should work the same for both static and non-static methods)
 */
it('infers static method calls on parent class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfMethodCall', 'string(foo)'],
    ['staticMethodCall', 'string(foo)'],
]);

it('infers static method calls on child class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfMethodCall', 'string(foo)'],
    ['staticMethodCall', 'string(bar)'],
    ['parentMethodCall', 'string(foo)'],
]);

/*
 * Property fetches
 */
it('infers property fetches on parent class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Foo::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfPropertyFetch', 'string(foo)'],
    ['staticPropertyFetch', 'string(foo)'],
]);

it('infers property fetches on child class', function (string $method, string $expectedType) {
    $methodDef = $this->classAnalyzer
        ->analyze(\Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::class)
        ->getMethodDefinition($method);
    expect($methodDef->type->getReturnType()->toString())->toBe($expectedType);
})->with([
    ['selfPropertyFetch', 'string(foo)'],
    ['staticPropertyFetch', 'string(bar)'],
    ['parentPropertyFetch', 'string(foo)'],
]);

/*
 * Complex static calls
 */

it('infers type of static method call and instance property fetch', function () {
    $this->classAnalyzer->analyze(ReferenceTypeResolverTest_Foo::class);
    $this->classAnalyzer->analyze(ReferenceTypeResolverTest_Bar::class);

    $type = getStatementType('(new ReferenceTypeResolverTest_Bar)->test()');

    expect($type->toString())->toBe('list{list{string(bar), int(21)}, list{string(foo), int(21)}}');
});
class ReferenceTypeResolverTest_Foo
{
    public $prop = 42;

    public function oreo()
    {
        return ['foo', $this->prop];
    }

    public function test()
    {
        return [static::oreo(), self::oreo()];
    }
}
class ReferenceTypeResolverTest_Bar extends ReferenceTypeResolverTest_Foo
{
    public $prop = 21;

    public function oreo()
    {
        return ['bar', $this->prop];
    }
}
$a = (new ReferenceTypeResolverTest_Bar)->test();

it('complex static call and property fetch', function () {
    $type = getStatementType('Dedoc\Scramble\Tests\Infer\Services\StaticCallsClasses\Bar::wow()');

    expect($type->toString())->toBe('string(foo)');
});

/*
 * Static method calls
 */
it('infers static method call type', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public static function foo ($a) {
        return $a;
    }
}
EOD)->getExpressionType("Foo::foo('wow')");

    expect($type->toString())->toBe('string(wow)');
});

it('infers static method call type with named args', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public static function foo ($a) {
        return $a;
    }
}
EOD)->getExpressionType("Foo::foo(a: 'wow')");

    expect($type->toString())->toBe('string(wow)');
});

it('infers static method call type with named unpacked args', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public static function foo ($a) {
        return $a;
    }
}
EOD)->getExpressionType("Foo::foo(...['a' => 'wow'])");

    expect($type->toString())->toBe('string(wow)');
});
