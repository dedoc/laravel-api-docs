<?php

namespace Dedoc\Scramble\OpenAPI;

use Illuminate\Support\Collection;

class Server extends OpenApiObject
{
    public function __construct(
        public string $url,
        public ?string $description = null,
        /** @var Collection<string, ServerVariable> */
        public Collection $variables = new Collection,
    )
    {
    }
}
