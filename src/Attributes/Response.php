<?php

namespace Dedoc\Scramble\Attributes;

use Attribute;

#[Attribute]
class Response
{
    /**
     * @param class-string $type
     */
    public function __construct(
        public string $type,
    )
    {
    }
}
