<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AppServiceProviderRateLimiterTest extends TestCase
{
    public function test_api_tenant_limiter_uses_route_site_id_when_present(): void
    {
        $request = Request::create('/api/config/site-route-id', 'GET');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['GET'], '/api/config/{site_id}', fn () => null);

            return $route->bind($request);
        });

        $limit = $this->resolveLimiter($request);

        $this->assertSame('site-route-id', $limit->key);
        $this->assertSame(200, $limit->maxAttempts);
    }

    public function test_api_tenant_limiter_falls_back_to_body_site_id_for_ingest_requests(): void
    {
        $request = Request::create('/api/log-consent', 'POST', [
            'site_id' => 'site-body-id',
        ]);
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['POST'], '/api/log-consent', fn () => null);

            return $route->bind($request);
        });

        $limit = $this->resolveLimiter($request);

        $this->assertSame('site-body-id', $limit->key);
    }

    protected function resolveLimiter(Request $request): object
    {
        $limiter = RateLimiter::limiter('api-tenant');

        $this->assertIsCallable($limiter);

        $limit = $limiter($request);

        if (is_array($limit)) {
            $limit = $limit[0];
        }

        return $limit;
    }
}
