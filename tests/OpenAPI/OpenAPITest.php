<?php

use Dedoc\Scramble\OpenAPI;

it('creates document', function () {
    $document = new OpenAPI\OpenAPI(
        info: $info = new OpenAPI\Info(
            title: 'Application',
            version: '0.1.0',
        ),
    );

    $document->servers
        ->push(new OpenAPI\Server(url: 'http://localhost'))
        ->push(new OpenAPI\Server(url: 'http://localhost'));

    dd($document->toArray());
});
