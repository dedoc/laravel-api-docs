<?php

namespace Dedoc\Scramble\Tests\Infer\stubs;

class InvokableFoo
{
    public function __invoke($bar)
    {
        return $bar;
    }
}
