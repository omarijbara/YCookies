<?php

namespace Tests\Feature\Api;

use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProxyConfigAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();

        config([
            'services.proxy.shared_secret' => 'proxy-shared-secret',
            'services.proxy.shared_secret_prev' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_proxy_config_healthcheck_is_public(): void
    {
        $this->get('/api/proxy-config/healthcheck')
            ->assertNoContent();
    }

    public function test_proxy_config_requires_a_valid_signature(): void
    {
        $domain = $this->createProxyDomain();

        $this->getJson("/api/proxy-config/{$domain->name}")
            ->assertStatus(401)
            ->assertJson([
                'error' => 'Missing X-Proxy-Signature header',
            ]);
    }

    public function test_proxy_config_returns_config_for_a_valid_signature(): void
    {
        $domain = $this->createProxyDomain();
        $signature = hash_hmac('sha256', strtolower($domain->name), 'proxy-shared-secret');

        $response = $this->withHeaders([
            'X-Proxy-Signature' => $signature,
        ])->getJson("/api/proxy-config/{$domain->name}");

        $response->assertOk()
            ->assertJsonPath('domain', $domain->name)
            ->assertJsonPath('origin.auth_token', 'origin-token')
            ->assertJsonPath('origin.url', 'https://origin.secure-proxy.test')
            ->assertHeader('X-Signature');

        $this->assertSame(
            '"' . $response->json('revision') . '"',
            $response->headers->get('ETag')
        );
    }

    protected function createProxyDomain(): Domain
    {
        $group = Group::create(['name' => 'Proxy Test Group']);

        return Domain::create([
            'group_id' => $group->id,
            'name' => 'secure-proxy.test',
            'site_id' => Str::random(32),
            'is_active' => true,
            'proxy_enabled' => true,
            'proxy_status' => 'active',
            'config_version' => 7,
            'origin_url' => 'https://origin.secure-proxy.test',
            'origin_host' => 'origin.secure-proxy.test',
            'origin_auth_token' => 'origin-token',
        ]);
    }
}
