<?php

namespace Dedoc\Scramble\OpenAPI;

use Illuminate\Support\Collection;

class ServerVariable extends OpenApiObject
{
    public function __construct(
        public string $default,
        /** @var Collection<int, string> */
        public Collection $enum = new Collection,
        public ?string $description = null,
    ) {
    }
}
