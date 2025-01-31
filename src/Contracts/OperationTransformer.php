<?php

namespace Dedoc\Scramble\Contracts;

use Dedoc\Scramble\Contexts\OperationTransformerContext;
use Dedoc\Scramble\Support\Generator\Operation;

interface OperationTransformer
{
    public function handle(Operation $operation, OperationTransformerContext $context);
}
