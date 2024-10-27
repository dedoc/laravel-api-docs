<?php

namespace Dedoc\Scramble\Tests\Infer\Visitors;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Services\ShallowAnalyzer;
use Dedoc\Scramble\Support\Type\TemplateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

function analyzeCodeShallowly_Test(string $code): Index
{
    return (new ShallowAnalyzer(['temp' => $code]))
        ->buildIndex(new Index);
}

function dumpDefinition(ClassDefinition $classDefinition) {
    $str = "class $classDefinition->name";
    if ($classDefinition->templateTypes) {
        $str .= ' <'.implode(', ', array_map(
                fn (TemplateType $t) => $t->toDefinitionString(),
                $classDefinition->templateTypes,
            )).'>';
    }
    if ($classDefinition->parentFqn) {
        $str .= " extends $classDefinition->parentFqn";
    }
    $str .= " {\n";
    foreach ($classDefinition->properties as $name => $property) {
        $propertyString = $property->type->toString().' $'.$name;
        if ($property->defaultType) {
            $propertyString .= ' = '.$property->defaultType->toString();
        }
        $propertyString .= ";\n";

        $str .= "  $propertyString";
    }
    $str .= "\n";
    foreach ($classDefinition->methods as $name => $methodDefinition) {
        $methodString = $name; // .' '.$methodDefinition->type->toString();
        if ($methodDefinition->type->templates) {
            $templatesString = collect($methodDefinition->type->templates)->map->toString()->join(', ');
            $methodString .= ' <'.$templatesString.'>';
        }
        $argsString = collect($methodDefinition->type->arguments)
            ->map(fn ($type, $name) => $type->toString().' $'.$name.(($default = $methodDefinition->argumentsDefaults[$name] ?? null) ? ' = '.$default->toString() : ''))
            ->join(', ');
        $methodString .= ' ('.$argsString.')';
        $methodString .= ': '.$methodDefinition->type->returnType->toString();
        $methodString .= ";\n";

        $str .= "  $methodString";
    }
    $str .= '}';
    dump($str);
}



test('fuck around and find out', function () {
//    Benchmark::dd([
//        fn () => analyzeCodeShallowly_Test(file_get_contents(__DIR__.'/../../../dictionaries/core.php')),
//        function () {
//            foreach ((require __DIR__.'/../../../dictionaries/classMap.php') ?: [] as $className => $serializedClassDefinition) {
//                unserialize($serializedClassDefinition);
//            }
//        }
//    ]);
//    Benchmark::dd(
//        fn () => analyzeCodeShallowly_Test(file_get_contents(__DIR__.'/../../../dictionaries/core.php')),
//        10,
//    );
//    Benchmark::measure(
//        fn () => $index = analyzeCodeShallowly_Test(
//                 file_get_contents((new \ReflectionClass(Model::class))->getFileName())
//               ),
//        10,
//    );
//    $index = analyzeCodeShallowly_Test(
//        file_get_contents((new \ReflectionClass(Model::class))->getFileName())
//    );


    $index = analyzeCodeShallowly_Test(file_get_contents(__DIR__.'/../../../dictionaries/core.php'));
//
//    (new Infer($index))->analyzeClass(Hehe_Not_Exception::class);

    //    $index = analyzeCodeShallowly_Test(
    //        file_get_contents((new \ReflectionClass(Model::class))->getFileName())
    //    );
    //    $index = analyzeCodeShallowly_Test(
    //        file_get_contents((new \ReflectionClass(Collection::class))->getFileName())
    //    );
//    $type = analyzeFile('<?php', [], $index)->getExpressionType('new Dedoc\Scramble\Tests\Infer\Visitors\Hehe_Not_Exception("wow")');
    //

//    dd($type->toString());
//    //
//        return;

    foreach ($index->classesDefinitions as $classDefinition) {
        dumpDefinition($classDefinition);
    }

    $a = 1;
});

class Hehe_Not_Exception extends HttpException {
    public function __construct(string $message = '', ?\Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(419, $message, $previous, $headers, $code);
    }
}

test('adds simplest class', function () {
    $code = <<<'EOL'
<?php

class Foo {}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    expect($index->classesDefinitions)->toHaveKey('Foo');
});

// Properties tests

test('basic properties', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    protected int $bar;
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->properties)->toHaveKey('bar')
        ->and($fooDefinition->getPropertyDefinition('bar')->type->toString())->toBe('int');
});

test('properties defaults', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    protected int $bar = 42;
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $barPropDefinition = $index->getClassDefinition('Foo')->getPropertyDefinition('bar');

    expect($barPropDefinition->defaultType->toString())->toBe('int(42)');
});

test('promoted properties', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    public function __construct(protected int $bar) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->properties)->toHaveKey('bar')
        ->and($fooDefinition->getPropertyDefinition('bar')->type->toString())->toBe('int');
});

test('promoted properties defaults', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    public function __construct(protected int $bar = 42) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $barPropDefinition = $index->getClassDefinition('Foo')->getPropertyDefinition('bar');

    expect($barPropDefinition->defaultType->toString())->toBe('int(42)');
});

test('orders promoted properties before not promoted', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    public bool $check;

    public function __construct(protected int $bar) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect(array_keys($fooDefinition->properties))->toBe(['bar', 'check']);
});

test('template type properties annotated in PHPDoc', function () {
    $code = <<<'EOL'
<?php

/**
 * @template TBar of int
 */
class Foo
{
    /** @var TBar */
    protected int $bar;
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->templateTypes)->toHaveCount(1)
        ->and($fooDefinition->templateTypes[0]->name)->toBe('TBar')
        ->and($fooDefinition->templateTypes[0]->is->toString())->toBe('int')
        ->and($fooDefinition->properties)->toHaveKey('bar')
        ->and($fooDefinition->getPropertyDefinition('bar')->type->toString())->toBe('TBar');
});

test('template type promoted properties annotated in PHPDoc', function () {
    $code = <<<'EOL'
<?php

/**
 * @template TBar of int
 */
class Foo
{
    /** @param TBar $bar */
    public function __construct(protected int $bar) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->getPropertyDefinition('bar')->type->toString())->toBe('TBar');
});

// Methods tests

test('basic methods', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    public function bar(string $foo): int {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->getMethodDefinition('bar')->type->toString())->toBe('(string): int');
});

test('methods arguments defaults', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    public function bar($foo = 'jar') {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->getMethodDefinition('bar')->argumentsDefaults['foo']->toString())->toBe('string(jar)');
});

test('methods arguments and return PHPDoc annotations', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    /**
     * @param string $foo
     * @return int
     */
    public function bar($foo) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->getMethodDefinition('bar')->type->toString())->toBe('(string): int');
});

test('methods arguments and return plain class template annotations', function () {
    $code = <<<'EOL'
<?php

/**
 * @template TFoo
 * @template TBar
 */
class Foo
{
    /**
     * @param TFoo $foo
     * @return TBar
     */
    public function bar($foo) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect(array_values($fooDefinition->getMethodDefinition('bar')->type->arguments)[0])->toBeInstanceOf(TemplateType::class)
        ->and($fooDefinition->getMethodDefinition('bar')->type->returnType)->toBeInstanceOf(TemplateType::class)
        ->and($fooDefinition->getMethodDefinition('bar')->type->toString())->toBe('(TFoo): TBar');
});

test('methods template types definitions annotations', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    /**
     * @template TFooLocal
     * @template TBarLocal
     * @param TFooLocal $foo
     * @return TBarLocal
     */
    public function bar($foo) {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect(array_values($fooDefinition->getMethodDefinition('bar')->type->arguments)[0])->toBeInstanceOf(TemplateType::class)
        ->and($fooDefinition->getMethodDefinition('bar')->type->returnType)->toBeInstanceOf(TemplateType::class)
        ->and($fooDefinition->getMethodDefinition('bar')->type->toString())->toBe('<TFooLocal, TBarLocal>(TFooLocal): TBarLocal');
});

test('this return annotation handled as self when in PHPDoc annotations', function () {
    $code = <<<'EOL'
<?php

class Foo
{
    /** @return $this */
    public function bar() {}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $fooDefinition = $index->getClassDefinition('Foo');

    expect($fooDefinition->getMethodDefinition('bar')->type->toString())->toBe('(): self');
});

// Namespaces
test('annotates namespaces', function () {
    $code = <<<'EOL'
<?php

#[NS('Illuminate\Http')]
class Foo {}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    expect($index->getClassDefinition('Illuminate\Http\Foo'))->not->toBeNull();
});

test('namespace keyword', function () {
    $code = <<<'EOL'
<?php
namespace Illuminate\Http;
class Foo {}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    expect($index->getClassDefinition('Illuminate\Http\Foo'))->not->toBeNull();
});

test('namespaced use', function () {
    $code = <<<'EOL'
<?php
use Illuminate\Http\Request;

class Foo
{
    public Request $request;
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    expect($index->getClassDefinition('Foo')->getPropertyDefinition('request')->type->toString())
        ->toBe('Illuminate\Http\Request');
});

// Extending classes

test('extending a class with templates replaces templates instantly', function () {
    $code = <<<'EOL'
<?php

/** @extends Foo<int, string> */
class Bar extends Foo
{
}

/**
 * @template T
 * @template Q
 */
class Foo
{
    /** @var T */
    public $request;

    /**
     * @return Q
     */
    public function wow(){}
}
EOL;

    $index = analyzeCodeShallowly_Test($code);

    $barDefinition = $index->getClassDefinition('Bar');

    expect($barDefinition->getPropertyDefinition('request')->type->toString())
        ->toBe('int')
        ->and($barDefinition->getMethodDefinition('wow')->type->toString())
        ->toBe('(): string');
});
