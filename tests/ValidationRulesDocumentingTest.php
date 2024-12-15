<?php

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\ApiKeySecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\DeepParametersMerger;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RulesToParameters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

use function Spatie\Snapshots\assertMatchesSnapshot;

function validationRulesToDocumentationWithDeep(array $rules)
{
    return (new DeepParametersMerger(collect(app()->make(RulesToParameters::class, ['rules' => $rules])->handle())))
        ->handle();
}

// @todo: move rules from here to Generator/Request/ValidationRulesDocumentation test

it('extract rules from array like rules', function () {
    $rules = [
        'id' => 'int',
        'some' => 'required|array',
        'some.*.id' => 'required|int',
        'some.*.name' => 'string',
    ];

    $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('extract rules from array rules', function () {
    $rules = [
        'foo' => 'array',
        'foo.id' => 'int',
    ];

    $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('supports array rule details', function () {
    $rules = [
        'destination' => 'array:lat,lon|required',
        'destination.lat' => 'numeric|required|min:54|max:60',
        'destination.lon' => 'numeric|required|min:20|max:28.5',
    ];

    $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(json_encode(collect($params)->map->toArray()->all()));
});

it('supports array rule params', function () {
    $rules = [
        'destination' => 'array:lat,lon|required',
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('extract rules from enum rule', function () {
    $rules = [
        'status' => new Enum(StatusValidationEnum::class),
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('extract rules from enum rule with only', function () {
    $rules = [
        'status' => (new Enum(StatusValidationEnum::class))->only([StatusValidationEnum::DRAFT, StatusValidationEnum::ARCHIVED]),
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params[0]->toArray()['schema'])->toBe([
        'type' => 'string',
        'enum' => [
            'draft',
            'archived',
        ],
    ]);
});

it('extract rules from enum rule with except', function () {
    $rules = [
        'status' => (new Enum(StatusValidationEnum::class))->except(StatusValidationEnum::DRAFT),
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params[0]->toArray()['schema'])->toBe([
        'type' => 'string',
        'enum' => [
            'published',
            'archived',
        ],
    ]);
});

it('extract rules from object like rules', function () {
    $rules = [
        'channels.agency' => 'nullable',
        'channels.agency.id' => 'nullable|int',
        'channels.agency.name' => 'nullable|string',
    ];

    $params = $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('supports uuid', function () {
    $rules = [
        'foo' => ['required', 'uuid', 'exists:App\Models\Section,id'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('extract rules from object like rules heavy case', function () {
    $rules = [
        'channel' => 'required',
        'channel.publisher' => 'nullable|array',
        'channels.publisher.id' => 'nullable|int',
        'channels.publisher.name' => 'nullable|string',
        'channels.channel_url' => 'filled|url',
        'channels.agency' => 'nullable',
        'channels.agency.id' => 'nullable|int',
        'channels.agency.name' => 'nullable|string',
    ];

    $params = $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('extract rules from object like rules with explicit array', function () {
    $rules = [
        'channels.publisher' => 'array',
        'channels.publisher.id' => 'int',
    ];

    $params = validationRulesToDocumentationWithDeep($rules);

    assertMatchesSnapshot(collect($params)->map->toArray()->all());
});

it('supports exists rule', function () {
    $rules = [
        'email' => 'required|email|exists:users,email',
    ];

    $type = app()->make(RulesToParameters::class, ['rules' => $rules])->handle()[0]->schema->type;

    expect($type)->toBeInstanceOf(StringType::class)
        ->and($type->format)->toBe('email');
});

it('supports image rule', function () {
    $rules = [
        'image' => 'required|image',
    ];

    $type = app()->make(RulesToParameters::class, ['rules' => $rules])->handle()[0]->schema->type;

    expect($type)->toBeInstanceOf(StringType::class)
        ->and($type->contentMediaType)->toBe('application/octet-stream');
});

it('supports file rule', function () {
    $rules = [
        'file' => 'required|file',
    ];

    $type = app()->make(RulesToParameters::class, ['rules' => $rules])->handle()[0]->schema->type;

    expect($type)->toBeInstanceOf(StringType::class)
        ->and($type->contentMediaType)->toBe('application/octet-stream');
});

it('converts min rule into "minimum" for numeric fields', function () {
    $rules = [
        'num' => ['int', 'min:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\NumberType::class)
        ->toHaveKey('minimum', 8);
});

it('converts max rule into "maximum" for numeric fields', function () {
    $rules = [
        'num' => ['int', 'max:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\NumberType::class)
        ->toHaveKey('maximum', 8);
});

it('converts min rule into "minLength" for string fields', function () {
    $rules = [
        'str' => ['string', 'min:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\StringType::class)
        ->toHaveKey('minLength', 8);
});

it('converts max rule into "maxLength" for string fields', function () {
    $rules = [
        'str' => ['string', 'max:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\StringType::class)
        ->toHaveKey('maxLength', 8);
});

it('converts min rule into "minItems" for array fields', function () {
    $rules = [
        'num' => ['array', 'min:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\ArrayType::class)
        ->toHaveKey('minItems', 8);
});

it('converts max rule into "maxItems" for array fields', function () {
    $rules = [
        'num' => ['array', 'max:8'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\ArrayType::class)
        ->toHaveKey('maxItems', 8);
});

it('documents nullable uri rule', function () {
    $rules = [
        'page_url' => ['nullable', 'url'],
    ];

    $params = app()->make(RulesToParameters::class, ['rules' => $rules])->handle();

    expect($params = collect($params)->all())
        ->toHaveCount(1)
        ->and($params[0]->schema->type)
        ->toBeInstanceOf(\Dedoc\Scramble\Support\Generator\Types\StringType::class)
        ->toHaveProperty('format', 'uri')
        ->toHaveProperty('nullable', true);
});

it('extracts rules from request->validate call', function () {
    RouteFacade::get('api/test', [ValidationRulesDocumenting_Test::class, 'index']);

    Scramble::routes(fn (Route $r) => $r->uri === 'api/test');
    $openApiDocument = app()->make(\Dedoc\Scramble\Generator::class)();

    assertMatchesSnapshot($openApiDocument);
});

it('extracts rules docs', function () {
    RouteFacade::get('api/test', [ValidationRulesWithDocs_Test::class, 'index']);

    Scramble::routes(fn (Route $r) => $r->uri === 'api/test');
    $openApiDocument = app()->make(\Dedoc\Scramble\Generator::class)();

    assertMatchesSnapshot($openApiDocument['paths']['/test']['get']['parameters']);
});

it('extracts rules docs from form request', function () {
    RouteFacade::get('api/test', [ValidationRulesWithDocsAndFormRequest_Test::class, 'index']);

    Scramble::routes(fn (Route $r) => $r->uri === 'api/test');
    $openApiDocument = app()->make(\Dedoc\Scramble\Generator::class)();

    assertMatchesSnapshot($openApiDocument['paths']['/test']['get']['parameters']);
});

it('extracts rules from Validator::make facade call', function () {
    $openApiDocument = generateForRoute(function () {
        return RouteFacade::get('api/test', [ValidationFacadeRulesDocumenting_Test::class, 'index']);
    });

    assertMatchesSnapshot($openApiDocument);
});

it('supports validation rules and form request at the same time', function () {
    RouteFacade::get('api/test', [ValidationRulesAndFormRequestAtTheSameTime_Test::class, 'index']);

    Scramble::routes(fn (Route $r) => $r->uri === 'api/test');
    $openApiDocument = app()->make(\Dedoc\Scramble\Generator::class)();

    assertMatchesSnapshot($openApiDocument);
});
class ValidationRulesDocumenting_Test
{
    /**
     * @return ValidationRulesDocumenting_TestResource
     */
    public function index(Request $request)
    {
        $request->validate([
            'content' => ['required', Rule::in('wow')],
        ]);

        return new ValidationRulesDocumenting_TestResource(12);
    }
}

class ValidationFacadeRulesDocumenting_Test
{
    public function index(Request $request)
    {
        Validator::make($request->all(), [
            'content' => ['required', Rule::in('wow')],
        ], attributes: []);
    }
}

class ValidationRulesDocumenting_TestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => 1,
        ];
    }
}

class ValidationRulesAndFormRequestAtTheSameTime_Test
{
    public function index(ValidationRulesAndFormRequestAtTheSameTime_TestFormRequest $request)
    {
        $request->validate([
            'from_validate_call' => ['required', 'string'],
        ]);
    }
}

class ValidationRulesWithDocs_Test
{
    public function index(Request $request)
    {
        $request->validate([
            /**
             * A foo prop.
             *
             * @example wow
             */
            'foo' => ['required', 'string'],
            // A bar prop.
            'bar' => 'string',
            /**
             * A type redefined prop.
             *
             * @var int
             */
            'var' => ['required', 'string'],
        ]);
    }
}

class ValidationRulesWithDocsAndFormRequest_Test
{
    public function index(FormRequestWithDocs_TestFormRequest $request)
    {
        $request->validate([
            /**
             * A foo prop.
             *
             * @example wow
             */
            'foo' => ['required', 'string'],
        ]);
    }
}

class ValidationRulesAndFormRequestAtTheSameTime_TestFormRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'from_form_request' => 'int',
        ];
    }
}

class FormRequestWithDocs_TestFormRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Wow, this is a comment!
            'from_form_request' => 'int',
        ];
    }
}

enum StatusValidationEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}

it('supports manual authentication info', function () {
    RouteFacade::get('api/test', [ControllerWithoutSecurity::class, 'index']);
    Scramble::routes(fn (Route $r) => $r->uri === 'api/test');

    Scramble::extendOpenApi(function (OpenApi $openApi) {
        $openApi->secure(
            ApiKeySecurityScheme::apiKey('query', 'api_token')
        );
    });
    $openApiDocument = app()->make(\Dedoc\Scramble\Generator::class)();

    assertMatchesSnapshot($openApiDocument);
});
class ControllerWithoutSecurity
{
    /**
     * @unauthenticated
     */
    public function index() {}
}
