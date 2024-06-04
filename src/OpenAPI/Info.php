<?php

namespace Dedoc\Scramble\OpenAPI;

class Info extends OpenApiObject
{
    public function __construct(
        public string $title,
        public string $version,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $termsOfService = null,
        // public ?Contact $contact = null,
        // public ?License $license = null,
    ) {

    }
}
