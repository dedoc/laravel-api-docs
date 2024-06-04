<?php

namespace Dedoc\Scramble\OpenAPI;

use Illuminate\Contracts\Support\Arrayable;

abstract class OpenApiObject implements Arrayable
{
    public function toArray()
    {
        return collect(get_object_vars($this))
            ->mapWithKeys(function ($item, $key) {
                $result = $item instanceof Arrayable ? $item->toArray() : $item;
                return $result ? [$key => $result] : [];
            })
            ->all();
    }
}
