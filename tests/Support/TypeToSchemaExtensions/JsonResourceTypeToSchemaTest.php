<?php

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Helpers\JsonResourceHelper;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\UnknownType;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\JsonResourceTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\ResponseTypeToSchema;

it('supports call to method', function () {
    $type = new Generic(JsonResourceTypeToSchemaTest_WithInteger::class, [new UnknownType]);

    $transformer = new TypeTransformer($infer = app(Infer::class), $components = new Components, [
        JsonResourceTypeToSchema::class,
    ]);
    $extension = new JsonResourceTypeToSchema($infer, $transformer, $components);

    expect($extension->toSchema($type)->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'res_int' => ['type' => 'integer'],
            'proxy_int' => ['type' => 'integer'],
        ],
        'required' => ['res_int', 'proxy_int'],
    ]);
});

it('supports parent toArray class', function (string $className, array $expectedSchemaArray) {
    $type = new Generic($className, [new UnknownType]);

    $transformer = new TypeTransformer($infer = app(Infer::class), $components = new Components, [
        JsonResourceTypeToSchema::class,
    ]);
    $extension = new JsonResourceTypeToSchema($infer, $transformer, $components);

    expect($extension->toSchema($type)->toArray())->toBe($expectedSchemaArray);
})->with([
    [JsonResourceTypeToSchemaTest_NestedSample::class, [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'foo' => ['type' => 'string', 'example' => 'bar'],
            'nested' => ['type' => 'string', 'example' => 'true'],
        ],
        'required' => ['id', 'name', 'foo', 'nested'],
    ]],
    [JsonResourceTypeToSchemaTest_Sample::class, [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ],
        'required' => ['id', 'name'],
    ]],
    [JsonResourceTypeToSchemaTest_SpreadSample::class, [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'foo' => ['type' => 'string', 'example' => 'bar'],
        ],
        'required' => ['id', 'name', 'foo'],
    ]],
    [JsonResourceTypeToSchemaTest_NoToArraySample::class, [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ],
        'required' => ['id', 'name'],
    ]],
]);
/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_NoToArraySample extends \Illuminate\Http\Resources\Json\JsonResource {}
/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_SpreadSample extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray($request): array
    {
        return [
            ...parent::toArray($request),
            'foo' => 'bar',
        ];
    }
}
/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_NestedSample extends JsonResourceTypeToSchemaTest_SpreadSample
{
    public function toArray($request): array
    {
        return [
            ...parent::toArray($request),
            'nested' => 'true',
        ];
    }
}
/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_Sample extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray($request)
    {
        return parent::toArray($request);
    }
}

it('handles withResponse for json api resource', function () {
    $type = new Generic(JsonResourceTypeToSchemaTest_WithResponseSample::class, [new UnknownType]);

    $transformer = new TypeTransformer($infer = app(Infer::class), $components = new Components, [
        JsonResourceTypeToSchema::class,
        ResponseTypeToSchema::class,
    ]);
    $extension = new JsonResourceTypeToSchema($infer, $transformer, $components);

    expect($extension->toResponse($type)->code)->toBe(429);
});

/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_WithResponseSample extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function withResponse(\Illuminate\Http\Request $request, \Illuminate\Http\JsonResponse $response)
    {
        $response->setStatusCode(429);
    }
}

/**
 * @property JsonResourceTypeToSchemaTest_User $resource
 */
class JsonResourceTypeToSchemaTest_WithInteger extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(\Illuminate\Http\Request $request)
    {
        return [
            'res_int' => $this->resource->getInteger(),
            'proxy_int' => $this->getInteger(),
        ];
    }
}

it('gets the underlying model when mixin is inline', function () {
    $infer = app(Infer::class);

    $model = JsonResourceHelper::modelType(
        $infer->analyzeClass(JsonResourceTypeToSchemaTest_WithIntegerInline::class),
        new Infer\Scope\GlobalScope,
    );

    expect($model->name)->toBe(JsonResourceTypeToSchemaTest_User::class);
});

/** @mixin JsonResourceTypeToSchemaTest_User */
class JsonResourceTypeToSchemaTest_WithIntegerInline extends \Illuminate\Http\Resources\Json\JsonResource {}

class JsonResourceTypeToSchemaTest_User extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';

    protected $visible = ['id', 'name'];

    public function getInteger(): int
    {
        return unknown();
    }
}

it('handles default in json api resource', function () {
    $type = new Generic(JsonResourceTypeToSchemaTest_WithDefault::class, [new UnknownType]);

    $transformer = new TypeTransformer($infer = app(Infer::class), $components = new Components, [
        JsonResourceTypeToSchema::class,
        ResponseTypeToSchema::class,
    ]);
    $extension = new JsonResourceTypeToSchema($infer, $transformer, $components);

    expect($extension->toSchema($type)->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'foo' => [
                'type' => 'string',
                'default' => 'fooBar',
            ],
        ],
        'required' => ['foo'],
    ]);
});
class JsonResourceTypeToSchemaTest_WithDefault extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(\Illuminate\Http\Request $request)
    {
        return [
            /**
             * @default fooBar
             */
            'foo' => $this->resource->foo,
        ];
    }
}
