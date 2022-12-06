<?php

namespace Dedoc\Scramble;

use Dedoc\Scramble\Infer\Extensions\ExpressionExceptionExtension;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceCallsTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceCreationInfer;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\PossibleExceptionInfer;
use Dedoc\Scramble\Support\InferExtensions\ResourceCollectionTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\ResponseFactoryTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\ValidatorTypeInfer;

class DefaultExtensions
{
    /**
     * @return ExpressionExceptionExtension[]
     */
    public static function infer(): array
    {
        return [
            new PossibleExceptionInfer(),

            new JsonResourceCallsTypeInfer(),
            new JsonResourceCreationInfer(),
            new JsonResourceTypeInfer(),
            new ValidatorTypeInfer(),
            new ResourceCollectionTypeInfer(),
            new ResponseFactoryTypeInfer(),
        ];
    }
}
