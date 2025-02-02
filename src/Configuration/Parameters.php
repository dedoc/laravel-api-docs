<?php

namespace Dedoc\Scramble\Configuration;

use Dedoc\Scramble\Data\Parameter;

class Parameters
{
    /**
     * @param  Parameter[]  $items
     */
    public function __construct(public array $items = []) {}

    public function use(array $items)
    {
        $this->items = $items;

        return $this;
    }

    public function all()
    {
        return $this->items;
    }

    public function append(Parameter $parameter)
    {
        $this->items[] = $parameter;

        return $this;
    }
}
