<?php

// Tests for resolving references behavior

use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\UnknownType;

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
        ->and($type->templateTypesMap)->toHaveKeys(['TProp'])
        ->and($type->templateTypesMap['TProp'])->toBeInstanceOf(UnknownType::class);
});

it('supports creating an object with a constructor', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_simple_constructor_and_property.php')
        ->getExpressionType('new Foo(132)');

    expect($type)->toBeInstanceOf(Generic::class)
        ->and($type->name)->toBe('Foo')
        ->and($type->templateTypesMap)->toHaveKeys(['TProp'])
        ->and($type->templateTypesMap['TProp']->toString())->toBe('int(132)')
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
    $type = analyzeFile(__DIR__ . '/files/class_with_method_that_returns_self.php')
        ->getExpressionType('(new Foo)->foo()');

    expect($type->toString())->toBe('Foo');
});

it('understands method calls type', function () {
    $type = analyzeFile(__DIR__.'/files/class_with_self_chain_calls_method.php')
        ->getExpressionType('(new Foo)->foo()->foo()->one()');

    expect($type->toString())->toBe('int(1)');
});

it('understands templated property fetch type value for property fetch', function () {
    $type = analyzeFile(__DIR__ . '/files/class_with_property_fetch_in_method.php')
        ->getExpressionType('(new Foo(42))->prop');

    expect($type->toString())->toBe('int(42)');
});

it('understands templated property fetch type value for property fetch called in method', function () {
    $type = analyzeFile(__DIR__ . '/files/class_with_property_fetch_in_method.php')
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

    expect($type->toString())->toBe('array{0: string(wow), 1: int(12), 2: int(42)}');
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
    $type = analyzeFile(<<<'EOD'
<?php
class Foo extends Bar {
}
class Bar {
    public function foo () {
        return 2;
    }
}
EOD)->getExpressionType('(new Foo)->foo()');

    expect($type->toString())->toBe('int(2)');
});

it('resolves call to parent class', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo extends Bar {
    public function foo () {
        return $this->two();
    }
}
class Bar {
    public function two () {
        return 2;
    }
}
EOD)->getClassDefinition('Foo');

    expect($type->methods['foo']->type->toString())->toBe('(): int(2)');
});

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
});

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
})->skip('Not implemented');

it('gets property type from parent class when constructed', function () {
    $type = analyzeFile(<<<'EOD'
<?php
class Foo extends Bar {
    public function foo () {
        return $this->barProp;
    }
}
class Bar {
    public $barProp;
    public function __construct($b) {
        $this->barProp = $b;
    }
}
EOD)//->getClassDefinition('Foo')
        ->getExpressionType('(new Foo(2))->barProp')
    ;

    dd($type);

    expect($type->methods['foo']->type->toString())->toBe('(): int(2)');
})->skip('not implemented');
