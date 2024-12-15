<?php

// Tests for resolving references behavior

use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\UnknownType;
use Dedoc\Scramble\Tests\Infer\stubs\InvokableFoo;

it('supports creating an object without constructor', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public $prop;
}
EOD
    )->getExpressionType('new Foo()');

    expect($type)->toBeInstanceOf(Generic::class)
        ->and($type->name)->toBe('Foo')
        ->and($type->toString())->toBe('Foo<unknown>')
        ->and($type->templateTypes)->toHaveCount(1)
        ->and($type->templateTypes[0])->toBeInstanceOf(UnknownType::class);
});

it('supports creating an object with a constructor', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_simple_constructor_and_property.php')
        ->getExpressionType('new Foo(132)');

    expect($type)->toBeInstanceOf(Generic::class)
        ->and($type->name)->toBe('Foo')
        ->and($type->templateTypes)->toHaveCount(1)
        ->and($type->templateTypes[0]->toString())->toBe('int(132)')
        ->and($type->toString())->toBe('Foo<int(132)>');
});

it('self template definition side effect works', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public $prop;
    public function setProp($a) {
        $this->prop = $a;
        return $this;
    }
}
EOD)->getExpressionType('(new Foo)->setProp(123)');

    expect($type->toString())->toBe('Foo<int(123)>');
});

it('evaluates self type', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_method_that_returns_self.php')
        ->getExpressionType('(new Foo)->foo()');

    expect($type->toString())->toBe('Foo');
});

it('understands method calls type', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_self_chain_calls_method.php')
        ->getExpressionType('(new Foo)->foo()->foo()->one()');

    expect($type->toString())->toBe('int(1)');
});

it('understands templated property fetch type value for property fetch', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_property_fetch_in_method.php')
        ->getExpressionType('(new Foo(42))->prop');

    expect($type->toString())->toBe('int(42)');
});

it('understands templated property fetch type value for property fetch called in method', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_property_fetch_in_method.php')
        ->getExpressionType('(new Foo(42))->foo()');

    expect($type->toString())->toBe('int(42)');
});

it('resolves nested templates', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public $prop;
    public function __construct($prop)
    {
        $this->prop = $prop;
    }
    public function foo($prop, $a) {
        return fn ($prop) => [$this->prop, $prop, $a];
    }
}
EOD)->getExpressionType('(new Foo("wow"))->foo("prop", 42)(12)');

    expect($type->toString())->toBe('list{string(wow), int(12), int(42)}');
});

it('doesnt resolve templates from not own definition', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo {
    public $a;
    public $prop;
    public function __construct($a, $prop)
    {
        $this->a = $a;
        $this->prop = $prop;
    }
    public function getProp() {
        return $this->prop;
    }
}
EOD)->getExpressionType('(new Foo(1, fn ($a) => $a))->getProp()');

    expect($type->toString())->toBe('<TA>(TA): TA');
});

it('resolves method call from parent class', function () {
    $type = analyzeClass(Mc_Foo::class)->getExpressionType('(new Mc_Foo)->foo()');

    expect($type->toString())->toBe('int(2)');
});
class Mc_Foo extends Mc_Bar {}
class Mc_Bar
{
    public function foo()
    {
        return 2;
    }
}

it('resolves call to parent class', function () {
    $type = analyzeClass(Cp_Foo::class)->getClassDefinition('Cp_Foo');

    expect($type->getMethodDefinition('foo')->type->toString())->toBe('(): int(2)');
});
class Cp_Foo extends Cp_Bar
{
    public function foo()
    {
        return $this->two();
    }
}
class Cp_Bar
{
    public function two()
    {
        return 2;
    }
}

it('resolves polymorphic call from parent class', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo extends Bar {
    public function foo () {
        return $this->bar();
    }
    public function two () {
        return 2;
    }
}
class Bar {
    public function bar () {
        return $this->two();
    }
}
EOD)->getClassDefinition('Foo');

    expect($type->methods['foo']->type->toString())->toBe('(): int(2)');
})->skip('is it really that needed?');

it('detects parent class calls cyclic reference', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo extends Bar {
    public function foo () {
        return $this->bar();
    }
}
class Bar {
    public function bar () {
        return $this->foo();
    }
}
EOD)->getClassDefinition('Foo');

    expect($type->methods['foo']->type->toString())->toBe('(): unknown');
});

it('gets property type from parent class when constructed', function () {
    $type = analyzeClass(Pt_Foo::class)
        ->getExpressionType('(new Pt_Foo(2))->foo()');

    expect($type->toString())->toBe('int(2)');
});
class Pt_Foo extends Pt_Bar
{
    public function foo()
    {
        return $this->barProp;
    }
}
class Pt_Bar
{
    public $barProp;

    public function __construct($b)
    {
        $this->barProp = $b;
    }
}

it('collapses the same types in union', function () {
    $type = analyzeClass(SameUnionTypes_Foo::class)
        ->getExpressionType('(new SameUnionTypes_Foo(2))->foo()');

    expect($type->toString())->toBe('int(1)');
});
class SameUnionTypes_Foo
{
    public function foo()
    {
        if (rand()) {
            return $this->bar();
        }

        return $this->car();
    }

    public function bar()
    {
        return 1;
    }

    public function car()
    {
        return 1;
    }
}

it('resolves invokable call from parent class', function () {
    $type = analyzeClass(InvokableFoo::class)->getExpressionType('(new Dedoc\Scramble\Tests\Infer\stubs\InvokableFoo)("foo")');

    expect($type->toString())->toBe('string(foo)');
});

it('handles invokable call to Closure type without failing (#636)', function () {
    $type = getStatementType('(new \Closure)("foo")');

    expect($type->toString())->toBe('unknown');
});
