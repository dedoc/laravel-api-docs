<?php

namespace Dedoc\Scramble\Infer\Scope;

use Dedoc\Scramble\Infer\ProjectAnalyzer;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;

class GlobalScope extends Scope
{
    public function __construct()
    {
        parent::__construct(
            app()->make(ProjectAnalyzer::class)->index, // ???
            new NodeTypesResolver(),
            new ScopeContext(),
            new FileNameResolver(new NameContext(new Throwing())),
        );
    }
}
