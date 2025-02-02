<?php

namespace Dedoc\Scramble\Tests\Attributes;

use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Parameter;
use Dedoc\Scramble\Data\Example;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

it('retrieves parameters from Parameter annotations', function () {
    $openApi = generateForRoute(fn (Router $r) => $r->get('api/test', ParameterController_ParameterAnnotationsTest::class));

    expect($openApi['paths']['/test']['get']['parameters'][0])
        ->toBe([
            'name' => 'per_page',
            'in' => 'query',
            'schema' => [
                'type' => 'integer',
                'default' => 15,
            ],
        ]);
});
class ParameterController_ParameterAnnotationsTest
{
    #[Parameter('query', 'per_page', type: 'int', default: 15)]
    public function __invoke() {}
}

it('supports simple example for Parameter annotations', function () {
    $openApi = generateForRoute(fn (Router $r) => $r->get('api/test', ParameterSimpleExampleController_ParameterAnnotationsTest::class));

    expect($openApi['paths']['/test']['get']['parameters'][0])
        ->toBe([
            'name' => 'per_page',
            'in' => 'query',
            'schema' => [
                'type' => 'integer',
                'default' => 15,
            ],
            'example' => 10,
        ]);
});
class ParameterSimpleExampleController_ParameterAnnotationsTest
{
    #[Parameter('query', 'per_page', type: 'int', default: 15, example: 10)]
    public function __invoke() {}
}

it('supports complex examples for Parameter annotations', function () {
    $openApi = generateForRoute(fn (Router $r) => $r->get('api/test', ParameterComplexExampleController_ParameterAnnotationsTest::class));

    expect($openApi['paths']['/test']['get']['parameters'][0])
        ->toBe([
            'name' => 'per_page',
            'in' => 'query',
            'schema' => [
                'type' => 'integer',
                'default' => 15,
            ],
            'examples' => [
                'max' => [
                    'value' => 99,
                    'summary' => 'Max amount of stuff',
                    'description' => 'Really big item',
                ],
            ],
        ]);
});
class ParameterComplexExampleController_ParameterAnnotationsTest
{
    #[Parameter('query', 'per_page', type: 'int', default: 15, examples: ['max' => new Example(99, 'Max amount of stuff', 'Really big item')])]
    public function __invoke() {}
}

it('merges parameter data with the data inferred from Parameter annotations', function () {
    $openApi = generateForRoute(fn (Router $r) => $r->get('api/test', ParameterOverridingController_ParameterAnnotationsTest::class));

    expect($openApi['paths']['/test']['get']['parameters'][0])
        ->toBe([
            'name' => 'per_page',
            'in' => 'query',
            'schema' => [
                'type' => 'integer',
                'default' => 15,
            ],
        ]);
});
class ParameterOverridingController_ParameterAnnotationsTest
{
    #[Parameter('query', 'per_page', default: 15)]
    public function __invoke(Request $request)
    {
        $request->validate(['per_page' => 'int']);
    }
}

it('supports subclass Parameter annotations', function () {
    $openApi = generateForRoute(fn (Router $r) => $r->get('api/test', QueryParameterController_ParameterAnnotationsTest::class));

    expect($openApi['paths']['/test']['get']['parameters'][0])
        ->toBe([
            'name' => 'per_page',
            'in' => 'header',
            'schema' => [
                'type' => 'integer',
                'default' => 15,
            ],
        ]);
});
class QueryParameterController_ParameterAnnotationsTest
{
    #[HeaderParameter('per_page', type: 'int', default: 15)]
    public function __invoke() {}
}

//  body parameters test, pay attention to required property!
