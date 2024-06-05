<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Dedoc\Scramble\Infer\Extensions\Event\FunctionCallEvent;
use Dedoc\Scramble\Infer\Extensions\FunctionReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeHelper;

class ArrayKeysReturnTypeExtension implements FunctionReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === 'array_keys';
    }

    public function getFunctionReturnType(FunctionCallEvent $event): ?Type
    {
        $argType = $event->getArg('array', 0);

        if (
            ! $argType instanceof ArrayType
            && ! $argType instanceof KeyedArrayType
        ) {
            return null;
        }

        if ($argType instanceof ArrayType) {
            return new ArrayType(value: $argType->key);
        }

        $index = 0;

        return new KeyedArrayType(array_map(
            function (ArrayItemType_ $item) use (&$index) {
                return new ArrayItemType_(
                    key: null,
                    value: TypeHelper::createTypeFromValue($item->key === null ? $index++ : $item->key),
                );
            },
            $argType->items,
        ));
    }
}
