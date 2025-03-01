<?php

namespace Dedoc\Scramble;

use Dedoc\Scramble\Configuration\GeneratorConfigCollection;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\Infer\Extensions\InferExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route as RouteFacade;

class Scramble
{
    const DEFAULT_API = 'default';

    public static $tagResolver = null;

    /**
     * @var array<int, array{callable(OpenApiType, string): bool, string|(callable(OpenApiType, string): string), string[], bool}>
     */
    public static array $enforceSchemaRules = [];

    public static bool $defaultRoutesIgnored = false;

    /**
     * Extensions registered using programmatic API.
     *
     * @var class-string<ExceptionToResponseExtension|OperationExtension|TypeToSchemaExtension|InferExtension>[]
     */
    public static array $extensions = [];

    /**
     * Whether to throw an exception during docs generation. When `false`,
     * documentation will be generated and issues added to the endpoint description
     * that failed generation. When `true`, the exception will be thrown and docs
     * generation will fail.
     */
    public static bool $throwOnError = false;

    /**
     * Disables registration of default API documentation routes.
     */
    public static function ignoreDefaultRoutes(): void
    {
        static::$defaultRoutesIgnored = true;
    }

    public static function registerApi(string $name, array $config = []): GeneratorConfig
    {
        return static::getConfigurationsInstance()->register($name, $config);
    }

    public static function configure(string $api = self::DEFAULT_API): GeneratorConfig
    {
        return static::getGeneratorConfig($api);
    }

    /**
     * Update open api document before finally rendering it.
     *
     * @deprecated
     */
    public static function extendOpenApi(callable $openApiExtender)
    {
        static::afterOpenApiGenerated($openApiExtender);
    }

    /**
     * Update Open API document before finally rendering it.
     */
    public static function afterOpenApiGenerated(callable $afterOpenApiGenerated)
    {
        static::configure()->withDocumentTransformers($afterOpenApiGenerated);
    }

    public static function routes(callable $routeResolver)
    {
        static::configure()->routes($routeResolver);
    }

    /**
     * @param  class-string<ExceptionToResponseExtension|OperationExtension|TypeToSchemaExtension|InferExtension>  $extensionClassName
     */
    public static function registerExtension(string $extensionClassName): void
    {
        static::$extensions[] = $extensionClassName;
    }

    /**
     * @param  class-string<ExceptionToResponseExtension|OperationExtension|TypeToSchemaExtension|InferExtension>[]  $extensionClassNames
     */
    public static function registerExtensions(array $extensionClassNames): void
    {
        static::$extensions = array_merge(static::$extensions, $extensionClassNames);
    }

    /**
     * Modify tag generation behaviour
     *
     * @param  callable(RouteInfo, Operation): string[]  $tagResolver
     */
    public static function resolveTagsUsing(callable $tagResolver)
    {
        static::$tagResolver = $tagResolver;
    }

    /**
     * @param  bool  $throw  When `true` documentation won't be generated in case of the error. When `false`,
     *                       documentation will be generated but errors will be available in `scramble:analyze` command.
     */
    public static function enforceSchema(callable $cb, string|callable $errorMessageGetter, array $ignorePaths = [], bool $throw = true)
    {
        static::$enforceSchemaRules[] = [$cb, $errorMessageGetter, $ignorePaths, $throw];
    }

    public static function preventSchema(string|array $schemaTypes, array $ignorePaths = [], bool $throw = true)
    {
        $forbiddenSchemas = Arr::wrap($schemaTypes);

        static::enforceSchema(
            fn ($schema, $path) => ! in_array($schema::class, $forbiddenSchemas),
            fn ($schema) => 'Schema ['.$schema::class.'] is not allowed.',
            $ignorePaths,
            $throw,
        );
    }

    public static function getSchemaValidator(): SchemaValidator
    {
        return new SchemaValidator(static::$enforceSchemaRules);
    }

    /**
     * @param  array<string, ServerVariable>  $variables
     */
    public static function defineServerVariables(array $variables)
    {
        static::configure()->withServerVariables($variables);
    }

    public static function registerUiRoute(string $path, string $api = 'default'): Route
    {
        $config = static::getGeneratorConfig($api);

        if (Context::hasHidden('scramble-all-access')) {
            $config->set('ignore_exclude_attributes', true);
        }

        return RouteFacade::get($path, function (Generator $generator) use ($api) {
            $config = static::getGeneratorConfig($api);

            return view('scramble::docs', [
                'spec' => $generator($config),
                'config' => $config,
            ]);
        })
            ->middleware($config->get('middleware', [RestrictedDocsAccess::class]));
    }

    public static function registerJsonSpecificationRoute(string $path, string $api = 'default'): Route
    {
        $config = static::getGeneratorConfig($api);

        if (Context::hasHidden('scramble-all-access')) {
            $config->set('ignore_exclude_attributes', true);
        }

        return RouteFacade::get($path, function (Generator $generator) use ($api) {
            $config = static::getGeneratorConfig($api);

            return response()->json($generator($config), options: JSON_PRETTY_PRINT);
        })
            ->middleware($config->get('middleware', [RestrictedDocsAccess::class]));
    }

    public static function getGeneratorConfig(string $api)
    {
        return static::getConfigurationsInstance()->get($api);
    }

    /** @internal */
    public static function getConfigurationsInstance(): GeneratorConfigCollection
    {
        return app(GeneratorConfigCollection::class);
    }

    public static function throwOnError(bool $throw = true): void
    {
        static::$throwOnError = $throw;
    }

    public static function shouldThrowOnError(): bool
    {
        return static::$throwOnError;
    }
}
