<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Tests\OperationExtensions;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\OperationBuilder;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Facades\Route as RouteFacade;

beforeEach(function () {
    $route = RouteFacade::get('/test', fn () => 'test');
    $this->routeInfo = new RouteInfo($route, app(FileParser::class), app(Infer::class));
    $this->config = (new GeneratorConfig(config('scramble')));
    $this->openApi = OpenApi::make('3.1.0')
        ->setInfo(
            InfoObject::make($this->config ->get('ui.title', $default = config('app.name')) ?: $default)
                ->setVersion($this->config ->get('info.version', '0.0.1'))
                ->setDescription($this->config ->get('info.description', ''))
        );;
});

it('can register a closure as an extension', function () {
    Scramble::registerExtension(function (Operation $operation, RouteInfo $routeInfo) {
        expect($routeInfo->isClosureBased())->toBeTrue();
    });

    /** @var OperationBuilder $builder */
    $builder = resolve(OperationBuilder::class);
    $builder->build($this->routeInfo, $this->openApi, $this->config);
});

it('can register an array of closures as extensions', function () {
    Scramble::registerExtensions([
        function (Operation $operation, RouteInfo $routeInfo) {
            expect($routeInfo->isClosureBased())->toBeTrue();
        },
        function (Operation $operation, RouteInfo $routeInfo) {
            expect($routeInfo->isClosureBased())->toBeTrue();
        },
        function (Operation $operation, RouteInfo $routeInfo) {
            expect($routeInfo->isClosureBased())->toBeTrue();
        }
    ]);

    /** @var OperationBuilder $builder */
    $builder = resolve(OperationBuilder::class);
    $builder->build($this->routeInfo, $this->openApi, $this->config);
});
