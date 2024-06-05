<?php

namespace Dedoc\Scramble\Infer\Analyzer;

use Dedoc\Scramble\Infer\Definition\FunctionLikeDefinition;
use Dedoc\Scramble\Infer\Scope\Scope;

class MethodAnalysisResult
{
    public function __construct(
        public Scope $scope,
        public FunctionLikeDefinition $definition,
    ) {
    }
}
