<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureXtreamRouteAllowed;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

class EnsureXtreamRouteAllowedTest extends TestCase
{
    private function makeMiddleware(bool $restrictEnabled = false): EnsureXtreamRouteAllowed
    {
        $settings = Mockery::mock(GeneralSettings::class);
        $settings->xtream_restrict_to_dedicated_port = $restrictEnabled;

        return new EnsureXtreamRouteAllowed($settings);
    }

    private function passThrough(EnsureXtreamRouteAllowed $middleware, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $middleware->handle($request, fn ($req) => new Response('OK', 200));
    }

    public function test_passes_when_xtream_feature_disabled(): void
    {
        config(['xtream.enabled' => false]);

        $middleware = $this->makeMiddleware(restrictEnabled: true);
        $request = Request::create('/player_api.php');

        $response = $this->passThrough($middleware, $request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_when_restrict_setting_disabled(): void
    {
        config(['xtream.enabled' => true]);

        $middleware = $this->makeMiddleware(restrictEnabled: false);
        $request = Request::create('/player_api.php');

        $response = $this->passThrough($middleware, $request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_with_xtream_request_header(): void
    {
        config(['xtream.enabled' => true]);

        $middleware = $this->makeMiddleware(restrictEnabled: true);
        $request = Request::create('/player_api.php');
        $request->headers->set('X-Xtream-Request', 'true');

        $response = $this->passThrough($middleware, $request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_returns_404_without_header_when_restricted(): void
    {
        config(['xtream.enabled' => true]);

        $middleware = $this->makeMiddleware(restrictEnabled: true);
        $request = Request::create('/player_api.php');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->passThrough($middleware, $request);
    }
}
