<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Group;
use App\Services\CoolifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoolifyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://admin.ycookies.test',
            'services.coolify.instance_url' => 'https://coolify.test',
            'services.coolify.api_token' => 'coolify-token',
            'services.coolify.app_uuid' => 'admin-uuid',
            'services.coolify.proxy_app_uuid' => 'proxy-uuid',
        ]);
    }

    public function test_sync_domains_clears_stale_proxy_routing_when_no_proxy_domains_remain(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid') {
                return Http::response([
                    'docker_compose_domains' => [
                        'node-proxy' => ['domain' => 'https://stale.customer.test'],
                    ],
                ], 200);
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid') {
                return Http::response(['ok' => true], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid/restart') {
                return Http::response(['ok' => true], 200);
            }

            return Http::response([], 500);
        });

        $result = app(CoolifyService::class)->syncDomains();

        $this->assertTrue($result['changed']);
        $this->assertSame([], $result['domains']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid'
                && $request['docker_compose_domains'][0]['name'] === 'node-proxy'
                && $request['docker_compose_domains'][0]['domain'] === '';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid/restart';
        });
    }

    public function test_sync_domains_ignores_admin_service_domains_when_proxy_domains_are_already_synced(): void
    {
        $group = Group::create(['name' => 'Coolify Group']);

        Domain::withoutEvents(function () use ($group) {
            Domain::create([
                'group_id' => $group->id,
                'name' => 'customer-proxy.test',
                'site_id' => Str::random(32),
                'is_active' => true,
                'proxy_enabled' => true,
                'origin_subdomain' => 'origin.customer-proxy.test',
                'origin_auth_token' => 'origin-token',
            ]);
        });

        Http::fake(function ($request) {
            if ($request->method() === 'GET' && $request->url() === 'https://coolify.test/api/v1/applications/proxy-uuid') {
                return Http::response([
                    'docker_compose_domains' => [
                        'laravel' => ['domain' => 'https://admin.ycookies.test'],
                        'node-proxy' => ['domain' => 'https://customer-proxy.test'],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $result = app(CoolifyService::class)->syncDomains();

        $this->assertFalse($result['changed']);
        $this->assertSame(['https://customer-proxy.test'], $result['domains']);
        Http::assertSentCount(1);
    }
}
