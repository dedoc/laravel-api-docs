<?php

use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Services\ConstFetchTypeGetter;

it('gets const type from array value', function () {
    $type = (new ConstFetchTypeGetter)(new GlobalScope, ConstFetchTypeGetterTest_Foo::class, 'ARRAY');

    expect($type->toString())->toBe('list{string(foo), string(bar)}');
});
class ConstFetchTypeGetterTest_Foo
{
    const ARRAY = ['foo', 'bar'];
}
