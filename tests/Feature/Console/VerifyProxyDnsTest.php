<?php

namespace Tests\Feature\Console;

use App\Models\Domain;
use App\Models\Group;
use App\Services\CoolifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerifyProxyDnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_origin_subdomain_domains_with_the_node_proxy_app(): void
    {
        $dispatcher = Domain::getEventDispatcher();
        Domain::unsetEventDispatcher();

        try {
            $group = Group::create(['name' => 'Proxy DNS Group']);
            $domain = Domain::create([
                'group_id' => $group->id,
                'name' => 'customer-proxy.test',
                'site_id' => Str::random(32),
                'is_active' => true,
                'proxy_enabled' => true,
                'proxy_status' => 'pending',
                'origin_subdomain' => 'origin.customer-proxy.test',
                'origin_auth_token' => 'origin-token',
            ]);

            $coolify = \Mockery::mock(CoolifyService::class);
            $coolify->shouldReceive('verifyDns')
                ->once()
                ->with('customer-proxy.test')
                ->andReturn(true);
            $coolify->shouldReceive('addDomainToApp')
                ->once()
                ->with('customer-proxy.test', true)
                ->andReturn(true);

            $this->app->instance(CoolifyService::class, $coolify);

            $this->artisan('ycookies:verify-proxy-dns')
                ->expectsOutputToContain('Checking 1 proxy-enabled domain(s)...')
                ->expectsOutputToContain('customer-proxy.test')
                ->assertSuccessful();

            $domain->refresh();

            $this->assertSame('active', $domain->proxy_status);
            $this->assertNotNull($domain->proxy_verified_at);
        } finally {
            Domain::setEventDispatcher($dispatcher);
        }
    }
}
