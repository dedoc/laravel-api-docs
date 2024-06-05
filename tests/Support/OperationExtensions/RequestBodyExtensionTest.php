<?php

use Illuminate\Support\Facades\Route as RouteFacade;

it('uses application/json media type as a default request media type', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__uses_application_json_as_default::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content'])
        ->toHaveKey('application/json')
        ->toHaveLength(1);
});
class RequestBodyExtensionTest__uses_application_json_as_default
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate(['foo' => 'string']);
    }
}

it('generates request body only for certain http methods', function (string $method, bool $isRequestBodyExpected) {
    $openApiDocument = generateForRoute(function () use ($method) {
        return RouteFacade::addRoute($method, 'api/test', [RequestBodyExtensionTest__generates_request_body_only_for_certain_http_methods::class, 'index']);
    });

    expect($openApiDocument['paths']['/test'][$method])
        ->toHaveKeys($isRequestBodyExpected ? ['requestBody'] : ['parameters'])
        ->and($openApiDocument['paths']['/test'][$method])
        ->not()->toHaveKeys($isRequestBodyExpected ? ['parameters'] : ['requestBody']);
})->with([
    ['post', true],
    ['put', true],
    ['patch', true],
    ['get', false],
    ['head', false],
    ['delete', false],
]);
class RequestBodyExtensionTest__generates_request_body_only_for_certain_http_methods
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate(['foo' => 'string']);
    }
}

it('allows manually defining a request media type', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__allows_manual_request_media_type::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content'])
        ->toHaveKey('application/xml')
        ->toHaveLength(1);
});
class RequestBodyExtensionTest__allows_manual_request_media_type
{
    /**
     * @requestMediaType application/xml
     */
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate(['foo' => 'string']);
    }
}

it('automatically infers multipart/form-data as request media type when some of body params is binary', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__automaticall_infers_form_data::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content'])
        ->toHaveKey('multipart/form-data')
        ->toHaveLength(1);
});
class RequestBodyExtensionTest__automaticall_infers_form_data
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate(['foo' => 'file']);
    }
}

it('automatically infers multipart/form-data as request media type when some of body params is binary on a deeper layers', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__automaticall_infers_form_data_from_deeper::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content'])
        ->toHaveKey('multipart/form-data')
        ->toHaveLength(1);
});
class RequestBodyExtensionTest__automaticall_infers_form_data_from_deeper
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate([
            'foo.*' => 'file',
        ]);
    }
}

it('extracts parameters, their defaults, and descriptions from calling request parameters retrieving methods with scalar types', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_scalar_types::class, 'index']);
    });

    expect($schema = $openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema'])
        ->toHaveLength(2)
        ->and($schema['properties'])
        ->toBe([
            'count' => [
                'type' => 'integer',
                'description' => 'How many things are there.',
                'default' => 10,
            ],
            'weight' => [
                'type' => 'number',
                'default' => 0.5,
            ],
            'is_foo' => [
                'type' => 'boolean',
                'default' => false,
            ],
            'name' => [
                'type' => 'string',
                'default' => 'John Doe',
            ],
        ])
        ->and($schema['required'] ?? null)
        ->toBeNull();
});
class RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_scalar_types
{
    public function index(Illuminate\Http\Request $request)
    {
        // How many things are there.
        $param = $request->integer('count', 10);

        $request->float('weight', 0.5);

        $request->boolean('is_foo');

        $request->string('name', 'John Doe');
    }
}

it('extracts parameters, their defaults, and descriptions from calling request parameters retrieving methods with enum', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_enum::class, 'index']);
    });

    expect($properties = $openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveLength(1)
        ->and($properties['status'])
        ->toBe([
            '$ref' => '#/components/schemas/RequestBodyExtensionTest__Status_Params_Extraction',
        ])
        ->and($openApiDocument['components']['schemas']['RequestBodyExtensionTest__Status_Params_Extraction'])
        ->toBe([
            'type' => 'string',
            'enum' => [
                'clubs',
                'diamonds',
                'hearts',
                'spades',
            ],
            'title' => 'RequestBodyExtensionTest__Status_Params_Extraction',
        ]);
});
class RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_enum
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->enum('status', RequestBodyExtensionTest__Status_Params_Extraction::class);
    }
}
enum RequestBodyExtensionTest__Status_Params_Extraction: string
{
    case Clubs = 'clubs';
    case Diamonds = 'diamonds';
    case Hearts = 'hearts';
    case Spades = 'spades';
}

it('extracts parameters, their defaults, and descriptions from calling request parameters retrieving methods with query', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_query::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['parameters'])
        ->toHaveLength(1)
        ->toBe([[
            'name' => 'in_query',
            'in' => 'query',
            'schema' => [
                'type' => 'string',
            ],
            'default' => 'foo',
        ]]);
});
class RequestBodyExtensionTest__extracts_parameters_from_retrieving_methods_with_query
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->query('in_query', 'foo');
    }
}

it('ignores parameter with @ignoreParam doc', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__ignores_parameter_with_ignore_param_doc::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [])
        ->toHaveLength(0);
});
class RequestBodyExtensionTest__ignores_parameter_with_ignore_param_doc
{
    public function index(Illuminate\Http\Request $request)
    {
        /** @ignoreParam */
        $request->integer('foo', 10);
    }
}

it('uses and overrides default param value when it is provided manually in doc', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__uses_and_overrides_default_param_value_when_it_is_provided_manually_in_doc::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema']['properties']['foo']['default'])
        ->toBe(15);
});
class RequestBodyExtensionTest__uses_and_overrides_default_param_value_when_it_is_provided_manually_in_doc
{
    public function index(Illuminate\Http\Request $request)
    {
        /** @default 15 */
        $request->integer('foo', 10);
    }
}

it('allows explicitly specifying parameter placement in query manually in doc', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__allows_explicitly_specifying_parameter_placement_in_query_manually_in_doc::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [])
        ->toBeEmpty()
        ->and($openApiDocument['paths']['/test']['post']['parameters'])
        ->toBe([[
            'name' => 'foo',
            'in' => 'query',
            'schema' => ['type' => 'integer'],
            'default' => 10,
        ]]);
});
class RequestBodyExtensionTest__allows_explicitly_specifying_parameter_placement_in_query_manually_in_doc
{
    public function index(Illuminate\Http\Request $request)
    {
        /** @query */
        $request->integer('foo', 10);
    }
}

it('allows specifying query position and default for params inferred from validation rules using validate method', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::post('api/test', [RequestBodyExtensionTest__allows_specifying_query_position_and_default_for_params_inferred_from_validation_rules_using_validate_method::class, 'index']);
    });

    expect($openApiDocument['paths']['/test']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toBe([
            'per_page' => [
                'type' => 'integer',
                'default' => 10,
            ],
        ])
        ->and($openApiDocument['paths']['/test']['post']['parameters'])
        ->toBe([[
            'name' => 'all',
            'in' => 'query',
            'schema' => [
                'type' => 'boolean',
            ],
        ]]);
});
class RequestBodyExtensionTest__allows_specifying_query_position_and_default_for_params_inferred_from_validation_rules_using_validate_method
{
    public function index(Illuminate\Http\Request $request)
    {
        $request->validate([
            /** @default 10 */
            'per_page' => 'integer',
            /** @query */
            'all' => 'boolean',
        ]);
    }
}

it('falls back to static analysis when cannot evaluate rules', function () {

});

it('ignores param in rules with annotation', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{id}', [RequestBodyExtensionTest__ignores_rules_param_with_annotation::class, 'index']);
    });

    expect($params = $openApiDocument['paths']['/test/{id}']['get']['parameters'])
        ->toHaveCount(1)
        ->and($params[0]['in'])->toBe('path');
});
class RequestBodyExtensionTest__ignores_rules_param_with_annotation
{
    public function index(Illuminate\Http\Request $request, string $id)
    {
        $request->validate([
            /** @ignoreParam */
            'id' => 'integer',
        ]);
    }
}
