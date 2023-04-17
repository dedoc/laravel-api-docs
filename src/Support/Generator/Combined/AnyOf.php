<?php

namespace Dedoc\Scramble\Support\Generator\Combined;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use InvalidArgumentException;

class AnyOf extends Type
{
    /** @var Type[] */
    private $items;

    public function __construct()
    {
        parent::__construct('anyOf');
        $this->items = [new StringType];
    }

    public function toArray(OpenApi $openApi)
    {
        return [
            'anyOf' => array_map(
                fn ($item) => $item->toArray($openApi),
                $this->items,
            ),
        ];
    }

    public function setItems($items)
    {
        if (collect($items)->contains(fn ($item) => ! $item instanceof Type)) {
            throw new InvalidArgumentException('All items should be instances of '.Type::class);
        }

        $this->items = $items;

        return $this;
    }
}
