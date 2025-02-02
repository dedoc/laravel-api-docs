<?php

namespace Dedoc\Scramble;

use Dedoc\Scramble\Configuration\GeneratorConfigCollection;
use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Configuration\ParametersExtractors;
use Dedoc\Scramble\Console\Commands\AnalyzeDocumentation;
use Dedoc\Scramble\Console\Commands\ExportDocumentation;
use Dedoc\Scramble\Data\Parameter;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\Infer\Extensions\ExtensionsBroker;
use Dedoc\Scramble\Infer\Extensions\IndexBuildingBroker;
use Dedoc\Scramble\Infer\Extensions\InferExtension;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthenticationExceptionToResponseExtension;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthorizationExceptionToResponseExtension;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\NotFoundExceptionToResponseExtension;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\ValidationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\IndexBuilders\IndexBuilder;
use Dedoc\Scramble\Support\InferExtensions\AbortHelpersExceptionInfer;
use Dedoc\Scramble\Support\InferExtensions\ArrayMergeReturnTypeExtension;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceCallsTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceCreationInfer;
use Dedoc\Scramble\Support\InferExtensions\JsonResourceExtension;
use Dedoc\Scramble\Support\InferExtensions\JsonResponseMethodReturnTypeExtension;
use Dedoc\Scramble\Support\InferExtensions\ModelExtension;
use Dedoc\Scramble\Support\InferExtensions\PossibleExceptionInfer;
use Dedoc\Scramble\Support\InferExtensions\ResourceCollectionTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\ResourceResponseMethodReturnTypeExtension;
use Dedoc\Scramble\Support\InferExtensions\ResponseFactoryTypeInfer;
use Dedoc\Scramble\Support\InferExtensions\ResponseMethodReturnTypeExtension;
use Dedoc\Scramble\Support\InferExtensions\TypeTraceInfer;
use Dedoc\Scramble\Support\InferExtensions\ValidatorTypeInfer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\AttributesParametersExtractor;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\MethodCallsParametersExtractor;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\AnonymousResourceCollectionTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\CollectionToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\CursorPaginatorTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\EloquentCollectionToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\EnumToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\JsonResourceTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\LengthAwarePaginatorTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\ModelToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\PaginatorTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\ResourceResponseTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\ResponseTypeToSchema;
use Dedoc\Scramble\Support\TypeToSchemaExtensions\VoidTypeToSchema;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScrambleServiceProvider extends PackageServiceProvider
{
    public $singletons = [
        PrettyPrinter::class => PrettyPrinter\Standard::class,
        GeneratorConfigCollection::class => GeneratorConfigCollection::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('scramble')
            ->hasConfigFile()
            ->hasCommand(ExportDocumentation::class)
            ->hasCommand(AnalyzeDocumentation::class)
            ->hasViews('scramble');

        $this->app->singleton(FileParser::class, function () {
            return new FileParser(
                (new ParserFactory)->createForHostVersion()
            );
        });

        $this->app->singleton(Index::class, function () {
            $index = new Index;
            foreach ((require __DIR__.'/../dictionaries/classMap.php') ?: [] as $className => $serializedClassDefinition) {
                $index->classesDefinitions[$className] = unserialize($serializedClassDefinition);
            }

            return $index;
        });

        $this->app->singleton(Infer::class);

        $this->app->when(ExtensionsBroker::class)
            ->needs('$extensions')
            ->give(function () {
                $extensions = array_merge(config('scramble.extensions', []), Scramble::$extensions);

                $inferExtensionsClasses = array_values(array_filter(
                    $extensions,
                    fn ($e) => is_a($e, InferExtension::class, true),
                ));

                $inferExtensionsClasses = array_merge([
                    ResponseMethodReturnTypeExtension::class,
                    JsonResourceExtension::class,
                    ResourceResponseMethodReturnTypeExtension::class,
                    JsonResponseMethodReturnTypeExtension::class,
                    ModelExtension::class,
                ], $inferExtensionsClasses);

                return array_merge(
                    [
                        new PossibleExceptionInfer,
                        new AbortHelpersExceptionInfer,

                        new JsonResourceCallsTypeInfer,
                        new JsonResourceCreationInfer,
                        new ValidatorTypeInfer,
                        new ResourceCollectionTypeInfer,
                        new ResponseFactoryTypeInfer,

                        new ArrayMergeReturnTypeExtension,

                        /* Keep this extension last, so the trace info is preserved. */
                        new TypeTraceInfer,
                    ],
                    array_map(function ($class) {
                        return app($class);
                    }, $inferExtensionsClasses)
                );
            });

        $this->app->when(IndexBuildingBroker::class)
            ->needs('$indexBuilders')
            ->give(function () {
                $extensions = array_merge(config('scramble.extensions', []), Scramble::$extensions);

                $indexBuilders = array_values(array_filter(
                    $extensions,
                    fn ($e) => is_a($e, IndexBuilder::class, true),
                ));

                return array_map(function ($class) {
                    return app($class);
                }, $indexBuilders);
            });

        $this->app->bind(TypeTransformer::class, function (Application $application, array $parameters) {
            $extensions = array_merge(config('scramble.extensions', []), Scramble::$extensions);

            $typesToSchemaExtensions = array_values(array_filter(
                $extensions,
                fn ($e) => is_a($e, TypeToSchemaExtension::class, true),
            ));

            $exceptionToResponseExtensions = array_values(array_filter(
                $extensions,
                fn ($e) => is_a($e, ExceptionToResponseExtension::class, true),
            ));

            return new TypeTransformer(
                $parameters['infer'] ?? $application->make(Infer::class),
                $parameters['context'],
                typeToSchemaExtensions: $parameters['typeToSchemaExtensions'] ?? array_merge([
                    EnumToSchema::class,
                    JsonResourceTypeToSchema::class,
                    ModelToSchema::class,
                    CollectionToSchema::class,
                    EloquentCollectionToSchema::class,
                    AnonymousResourceCollectionTypeToSchema::class,
                    CursorPaginatorTypeToSchema::class,
                    PaginatorTypeToSchema::class,
                    LengthAwarePaginatorTypeToSchema::class,
                    ResponseTypeToSchema::class,
                    ResourceResponseTypeToSchema::class,
                    VoidTypeToSchema::class,
                ], $typesToSchemaExtensions),
                exceptionToResponseExtensions: $parameters['exceptionToResponseExtensions'] ?? array_merge([
                    ValidationExceptionToResponseExtension::class,
                    AuthorizationExceptionToResponseExtension::class,
                    AuthenticationExceptionToResponseExtension::class,
                    NotFoundExceptionToResponseExtension::class,
                    HttpExceptionToResponseExtension::class,
                ], $exceptionToResponseExtensions),
            );
        });

        Scramble::configure()
            ->withOperationTransformers(function (OperationTransformers $transformers) {
                $extensions = array_merge(config('scramble.extensions', []), Scramble::$extensions);

                $operationExtensions = array_values(array_filter(
                    $extensions,
                    fn ($e) => is_a($e, OperationExtension::class, true),
                ));

                $transformers->append($operationExtensions);
            });
    }

    public function bootingPackage()
    {
        Scramble::configure()
            ->useConfig(config('scramble'));

        $this->app->booted(function (Application $app) {
            Scramble::configure()
                ->withParametersExtractors(function (ParametersExtractors $parametersExtractors) {
                    $parametersExtractors->append([
                        MethodCallsParametersExtractor::class,
                        AttributesParametersExtractor::class,
                    ]);
                });

            $this->registerRoutes();
        });
    }

    private function registerRoutes(): void
    {
        foreach (Scramble::getConfigurationsInstance()->all() as $api => $generatorConfig) {
            /** @var Router $router */
            $router = $this->app->get(Router::class);

            if ($generatorConfig->uiRoute) {
                $cb = is_callable($generatorConfig->uiRoute)
                    ? $generatorConfig->uiRoute
                    : fn ($router, $action) => $router->get($generatorConfig->uiRoute, $action);

                $cb($router, function (Generator $generator) use ($api) {
                    $config = Scramble::getGeneratorConfig($api);

                    return view('scramble::docs', [
                        'spec' => $generator($config),
                        'config' => $config,
                    ]);
                })->middleware($generatorConfig->get('middleware', [RestrictedDocsAccess::class]));
            }

            if ($generatorConfig->documentRoute) {
                $cb = is_callable($generatorConfig->documentRoute)
                    ? $generatorConfig->documentRoute
                    : fn ($router, $action) => $router->get($generatorConfig->documentRoute, $action);

                $cb($router, function (Generator $generator) use ($api) {
                    $config = Scramble::getGeneratorConfig($api);

                    return response()->json($generator($config), options: JSON_PRETTY_PRINT);
                })->middleware($generatorConfig->get('middleware', [RestrictedDocsAccess::class]));
            }
        }
    }
}
