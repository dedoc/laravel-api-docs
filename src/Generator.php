<?php

namespace Dedoc\Scramble;

use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Exceptions\RouteAware;
use Dedoc\Scramble\OpenApiVisitor\SchemaEnforceVisitor;
use Dedoc\Scramble\Support\ContainerUtils;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Generator\UniqueNamesOptionsCollection;
use Dedoc\Scramble\Support\OperationBuilder;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\ServerFactory;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Generator
{
    public array $exceptions = [];

    protected bool $throwExceptions = true;

    public function __construct(
        private OperationBuilder $operationBuilder,
        private Infer $infer
    ) {}

    public function setThrowExceptions(bool $throwExceptions): static
    {
        $this->throwExceptions = $throwExceptions;

        return $this;
    }

    public function __invoke(?GeneratorConfig $config = null)
    {
        $config ??= Scramble::getGeneratorConfig(Scramble::DEFAULT_API);

        $openApi = $this->makeOpenApi($config);
        $context = new OpenApiContext($openApi, $config);
        $typeTransformer = $this->buildTypeTransformer($context);

        $this->getRoutes($config)
            ->map(function (Route $route, int $index) use ($openApi, $config, $typeTransformer) {
                try {
                    $operation = $this->routeToOperation($openApi, $route, $config, $typeTransformer);
                    $operation->setAttribute('index', $index);

                    return $operation;
                } catch (Throwable $e) {
                    if ($e instanceof RouteAware) {
                        $e->setRoute($route);
                    }

                    if (config('app.debug', false)) {
                        $method = $route->methods()[0];
                        $action = $route->getAction('uses');

                        dump("Error when analyzing route '$method $route->uri' ($action): {$e->getMessage()} – ".($e->getFile().' on line '.$e->getLine()));
                        logger()->error("Error when analyzing route '$method $route->uri' ($action): {$e->getMessage()} – ".($e->getFile().' on line '.$e->getLine()));
                    }

                    throw $e;
                }
            })
            ->filter() // Closure based routes are filtered out for now, right here
            ->sortBy($this->createOperationsSorter())
            ->each(fn (Operation $operation) => $openApi->addPath(
                Path::make(
                    (string) Str::of($operation->path)
                        ->replaceFirst($config->get('api_path', 'api'), '')
                        ->trim('/')
                )->addOperation($operation)
            ))
            ->toArray();

        $this->setUniqueOperationId($openApi);

        $this->moveSameAlternativeServersToPath($openApi);

        foreach ($config->documentTransformers->all() as $openApiTransformer) {
            $openApiTransformer = is_callable($openApiTransformer)
                ? $openApiTransformer
                : ContainerUtils::makeContextable($openApiTransformer, [
                    TypeTransformer::class => $typeTransformer,
                ]);

            if (is_callable($openApiTransformer)) {
                $openApiTransformer($openApi, $context);

                continue;
            }

            if ($openApiTransformer instanceof DocumentTransformer) {
                $openApiTransformer->handle($openApi, $context);

                continue;
            }

            throw new InvalidArgumentException('(callable(OpenApi, OpenApiContext): void)|DocumentTransformer type for document transformer expected, received '.$openApiTransformer::class);
        }

        return $openApi->toArray();
    }

    private function createOperationsSorter(): array
    {
        $defaultSortValue = fn (Operation $o) => $o->tags[0];

        return [
            fn (Operation $a, Operation $b) => $a->getAttribute('groupWeight', INF) <=> $b->getAttribute('groupWeight', INF),
            fn (Operation $a, Operation $b) => $a->getAttribute('weight', INF) <=> $b->getAttribute('weight', INF), // @todo manual endpoint sorting
            fn (Operation $a, Operation $b) => $defaultSortValue($a) <=> $defaultSortValue($b),
            fn (Operation $a, Operation $b) => $a->getAttribute('index', INF) <=> $b->getAttribute('index', INF),
        ];
    }

    private function makeOpenApi(GeneratorConfig $config)
    {
        $openApi = OpenApi::make('3.1.0')
            ->setComponents(new Components)
            ->setInfo(
                InfoObject::make($config->get('ui.title', $default = config('app.name')) ?: $default)
                    ->setVersion($config->get('info.version', '0.0.1'))
                    ->setDescription($config->get('info.description', ''))
            );

        [$defaultProtocol] = explode('://', url('/'));
        $servers = $config->get('servers') ?: [
            '' => ($domain = $config->get('api_domain'))
                ? $defaultProtocol.'://'.$domain.'/'.$config->get('api_path', 'api')
                : $config->get('api_path', 'api'),
        ];
        foreach ($servers as $description => $url) {
            $openApi->addServer(
                (new ServerFactory($config->serverVariables->all()))->make(url($url ?: '/'), $description)
            );
        }

        return $openApi;
    }

    private function getRoutes(GeneratorConfig $config): Collection
    {
        return collect(RouteFacade::getRoutes())
            ->pipe(function (Collection $c) {
                $onlyRoute = $c->first(function (Route $route) {
                    if (! is_string($route->getAction('controller'))) {
                        return false;
                    }

                    if (! is_string($route->getAction('uses'))) {
                        return false;
                    }

                    try {
                        $reflection = new \ReflectionMethod(...explode('@', $route->getAction('uses')));

                        if (str_contains($reflection->getDocComment() ?: '', '@only-docs')) {
                            return true;
                        }
                    } catch (Throwable) {
                    }

                    return false;
                });

                return $onlyRoute ? collect([$onlyRoute]) : $c;
            })
            ->filter(function (Route $route) {
                return ! ($name = $route->getAction('as')) || ! Str::startsWith($name, 'scramble');
            })
            ->filter($config->routes())
            ->filter(fn (Route $r) => $r->getAction('controller'))
            ->filter(function (Route $route) use ($config) {
                if (! is_string($route->getAction('uses'))) {
                    return true;
                }

                if ($config->get('ignore_exclude_attributes')) {
                    return true;
                }

                try {
                    $classReflection = new \ReflectionClass(explode('@', $route->getAction('uses'))[0]);

                    if (count($classReflection->getAttributes(ExcludeAllRoutesFromDocs::class))) {
                        return false;
                    }
                } catch (Throwable) {
                }

                try {
                    $reflection = new \ReflectionMethod(...explode('@', $route->getAction('uses')));

                    if (count($reflection->getAttributes(ExcludeRouteFromDocs::class))) {
                        return false;
                    }
                } catch (Throwable) {
                }

                return true;
            })
            ->values();
    }

    private function buildTypeTransformer(OpenApiContext $context): TypeTransformer
    {
        return app()->make(TypeTransformer::class, [
            'context' => $context,
        ]);
    }

    private function routeToOperation(OpenApi $openApi, Route $route, GeneratorConfig $config, TypeTransformer $typeTransformer)
    {
        $routeInfo = new RouteInfo($route, $this->infer, $typeTransformer);

        if (! $routeInfo->isClassBased()) {
            return null;
        }

        $operation = $this->operationBuilder->build($routeInfo, $openApi, $config, $typeTransformer);

        $this->ensureSchemaTypes($route, $operation);

        return $operation;
    }

    private function ensureSchemaTypes(Route $route, Operation $operation): void
    {
        if (! Scramble::getSchemaValidator()->hasRules()) {
            return;
        }

        [$traverser, $visitor] = $this->createSchemaEnforceTraverser($route);

        $traverser->traverse($operation, ['', 'paths', $operation->path, $operation->method]);
        $references = $visitor->popReferences();

        /** @var Reference $ref */
        foreach ($references as $ref) {
            if ($resolvedType = $ref->resolve()) {
                $traverser->traverse($resolvedType, ['', 'components', $ref->referenceType, $ref->getUniqueName()]);
            }
        }
    }

    private function createSchemaEnforceTraverser(Route $route)
    {
        $traverser = new OpenApiTraverser([$visitor = new SchemaEnforceVisitor($route, $this->throwExceptions, $this->exceptions)]);

        return [$traverser, $visitor];
    }

    private function moveSameAlternativeServersToPath(OpenApi $openApi)
    {
        foreach (collect($openApi->paths)->groupBy('path') as $pathsGroup) {
            if ($pathsGroup->isEmpty()) {
                continue;
            }

            $operations = collect($pathsGroup->pluck('operations')->flatten());

            $operationsHaveSameAlternativeServers = $operations->count()
                && $operations->every(fn (Operation $o) => count($o->servers))
                && $operations->unique(function (Operation $o) {
                    return collect($o->servers)->map(fn (Server $s) => $s->url)->join('.');
                })->count() === 1;

            if (! $operationsHaveSameAlternativeServers) {
                continue;
            }

            $pathsGroup->every->servers($operations->first()->servers);

            foreach ($operations as $operation) {
                $operation->servers([]);
            }
        }
    }

    private function setUniqueOperationId(OpenApi $openApi)
    {
        $names = new UniqueNamesOptionsCollection;

        $this->foreachOperation($openApi, function (Operation $operation) use ($names) {
            $names->push($operation->getAttribute('operationId'));
        });

        $this->foreachOperation($openApi, function (Operation $operation, $index) use ($names) {
            $name = $operation->getAttribute('operationId');

            $operation->setOperationId($names->getUniqueName($name, function (string $fallback) use ($index) {
                return "{$fallback}_{$index}";
            }));
        });
    }

    private function foreachOperation(OpenApi $openApi, callable $callback)
    {
        foreach (collect($openApi->paths)->groupBy('path') as $pathsGroup) {
            if ($pathsGroup->isEmpty()) {
                continue;
            }

            $operations = collect($pathsGroup->pluck('operations')->flatten());

            foreach ($operations as $index => $operation) {
                $callback($operation, $index);
            }
        }
    }
}
