<?php

use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

function RoutesFilteringTest_generateForRoutes($callback)
{
    $routesUris = array_map(
        fn (Route $r) => $r->uri,
        $callback()
    );

    Scramble::routes(fn (Route $r) => in_array($r->uri, $routesUris));

    return app()->make(\Dedoc\Scramble\Generator::class)();
}

it('filters routes with ExcludeRouteFromDocs attribute', function () {
    $documentation = RoutesFilteringTest_generateForRoutes(fn () => [
        RouteFacade::post('foo', [RoutesFilteringTest_ControllerA::class, 'foo']),
        RouteFacade::post('bar', [RoutesFilteringTest_ControllerA::class, 'bar']),
    ]);

    expect(array_keys($documentation['paths']))->toBe(['/foo']);
});
class RoutesFilteringTest_ControllerA
{
    public function foo() {}

    #[ExcludeRouteFromDocs]
    public function bar() {}
}

it('filters all controller routes with ExcludeAllRoutesFromDocs attribute', function () {
    $documentation = RoutesFilteringTest_generateForRoutes(fn () => [
        RouteFacade::post('foo', [RoutesFilteringTest_ControllerB::class, 'foo']),
        RouteFacade::post('bar', [RoutesFilteringTest_ControllerB::class, 'bar']),
    ]);

    expect(array_keys($documentation['paths'] ?? []))->toBe([]);
});

#[ExcludeAllRoutesFromDocs]
class RoutesFilteringTest_ControllerB
{
    public function foo() {}

    public function bar() {}
}
