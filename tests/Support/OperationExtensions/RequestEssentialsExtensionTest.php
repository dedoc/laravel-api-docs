<?php

use Dedoc\Scramble\Tests\Files\SampleUserModel;
use Dedoc\Scramble\Tests\Files\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;

it('uses getRouteKeyName to determine model route key type', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{model}', [Foo_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{model}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'string')
        ->toHaveKey('description', 'The model name');
});
class ModelWithCustomRouteKeyName extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';

    public function getRouteKeyName()
    {
        return 'name'; // this is a string column
    }
}
class Foo_RequestEssentialsExtensionTest_Controller
{
    public function foo(ModelWithCustomRouteKeyName $model) {}
}

it('correctly handles not request class with rules method', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{model}', [Foo_RequestRulesTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{model}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'integer')
        ->toHaveKey('description', 'The model ID');
});
class ModelWithRulesMethod extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';

    public function rules()
    {
        return [];
    }
}
class Foo_RequestRulesTest_Controller
{
    public function foo(ModelWithRulesMethod $model) {}
}

it('uses explicit binding to infer more info about path params using Route::model', function () {
    RouteFacade::model('model_bound', RequestEssentialsExtensionTest_SimpleModel::class);

    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{model_bound}', [Foo_RequestExplicitlyBoundTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{model_bound}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'integer')
        ->toHaveKey('description', 'The model bound ID');
});
it('uses explicit binding to infer more info about path params using Route::bind with typehint', function () {
    RouteFacade::bind('model_bound', fn ($value): RequestEssentialsExtensionTest_SimpleModel => RequestEssentialsExtensionTest_SimpleModel::findOrFail($value));

    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{model_bound}', [Foo_RequestExplicitlyBoundTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{model_bound}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'integer')
        ->toHaveKey('description', 'The model bound ID');
});
class RequestEssentialsExtensionTest_SimpleModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';
}
class Foo_RequestExplicitlyBoundTest_Controller
{
    public function foo() {}
}

it('handles custom key from route to determine model route key type', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{user:name}', [CustomKey_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{user}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'string')
        ->toHaveKey('description', 'The user name');
});

it('determines default model route key type', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{user}', [CustomKey_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{user}']['get']['parameters'][0])
        ->toHaveKey('schema.type', 'integer')
        ->toHaveKey('description', 'The user ID');
});
class CustomKey_RequestEssentialsExtensionTest_Controller
{
    public function foo(SampleUserModel $user) {}
}

it('determines default route key type for union', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{user}', [UnionKey_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{user}']['get']['parameters'][0]['schema'])
        ->toBe([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
});
class UnionKey_RequestEssentialsExtensionTest_Controller
{
    public function foo(Request $request, string|int $user)
    {
        $request->validate(['foo' => 'required']);
    }
}

it('determines route key type for nullable', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{user}', [NullableKey_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{user}']['get']['parameters'][0]['schema'])
        ->toBe([
            'type' => ['integer', 'null'],
        ]);
});
class NullableKey_RequestEssentialsExtensionTest_Controller
{
    public function foo(Request $request, ?int $user)
    {
        $request->validate(['foo' => 'required']);
    }
}

it('handles enum in route parameter', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test/{status}', [EnumParameter_RequestEssentialsExtensionTest_Controller::class, 'foo']);
    });

    expect($openApiDocument['paths']['/test/{status}']['get']['parameters'][0])
        ->toHaveKey('schema.$ref', '#/components/schemas/Status');
});
class EnumParameter_RequestEssentialsExtensionTest_Controller
{
    public function foo(Status $status) {}
}
