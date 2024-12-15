<?php

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\JsonResourceTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\ModelToSchema;
use Dedoc\Scramble\Tests\Files\SamplePostModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->infer = app(Infer::class);
});

/**
 * @return array{0: \Dedoc\Scramble\Support\Generator\Types\Type, 1: Components}
 */
function JsonResourceExtensionTest_analyze(Infer $infer, string $class)
{
    $transformer = new TypeTransformer($infer, $components = new Components, [
        ModelToSchema::class,
        JsonResourceTypeToSchema::class,
    ]);
    $extension = new JsonResourceTypeToSchema($infer, $transformer, $components);

    $type = new ObjectType($class);

    $openApiType = $extension->toSchema($type);

    return [$openApiType, $components];
}

it('supports whenHas', function () {
    [$schema] = JsonResourceExtensionTest_analyze($this->infer, JsonResourceExtensionTest_WhenHas::class);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'user' => [
                '$ref' => '#/components/schemas/SampleUserModel',
            ],
            'value' => [
                'type' => 'integer',
                'example' => 42,
            ],
            'default' => [
                'anyOf' => [
                    [
                        'type' => 'string',
                        'enum' => ['foo'],
                    ],
                    [
                        'type' => 'integer',
                        'enum' => [42],
                    ],
                ],
            ],
        ],
        'required' => ['default'],
    ]);
});
/** @mixin SamplePostModel */
class JsonResourceExtensionTest_WhenHas extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'user' => $this->whenHas('user'),
            'value' => $this->whenHas('user', 42),
            'default' => $this->whenHas('user', 42, 'foo'),
        ];
    }
}
