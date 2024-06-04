<?php

namespace Dedoc\Scramble\OpenAPI;

use Illuminate\Support\Collection;

class OpenAPI extends OpenApiObject
{
    public function __construct(
        public Info $info,
        public string $openapi = '3.1.0',
        public ?string $jsonSchemaDialect = null,
        /** @var Collection<int, Server> */
        public Collection $servers = new Collection,
        /** @var Collection<string, PathItem> */
        public Collection $paths = new Collection,
        // public array $webhooks = [],
        public ?Components $components = null,
        /** @var Collection<int, SecurityRequirement> */
        public Collection $security = new Collection,
        /** @var Collection<int, Tag> */
        public Collection $tags = new Collection,
        // public ?ExternalDocumentation $externalDocs = null,
    ) {
    }
}
