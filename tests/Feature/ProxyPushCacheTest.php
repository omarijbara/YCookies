<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Observers\DomainObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * The Node proxy reads raw, UNPREFIXED keys (proxy_cfg:{host}) via ioredis.
 * Laravel's global redis prefix (config database.redis.options.prefix) would
 * silently divert pushed configs to a key the proxy never reads, leaving up
 * to 1h of stale consent config after every admin change. These tests pin
 * the contract: shared keys go through the prefixless 'proxy' connection.
 */
class ProxyPushCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_and_pubsub_redis_connections_have_no_key_prefix(): void
    {
        $this->assertSame('', config('database.redis.proxy.prefix'),
            "The 'proxy' connection must write raw keys the Node proxy can read");
        $this->assertSame('', config('database.redis.pubsub.prefix'),
            "The 'pubsub' connection must publish on raw channel names");
        $this->assertNotSame('', config('database.redis.options.prefix'),
            'Precondition: the global prefix is non-empty, so the dedicated connections matter');
    }

    public function test_config_push_writes_raw_proxy_cfg_key_on_unprefixed_connection(): void
    {
        Queue::fake();

        $domain = Domain::factory()->create([
            'name' => 'push-cache-test.example',
            'proxy_enabled' => true,
            'is_active' => true,
            'origin_url' => 'https://origin.example',
        ]);

        $proxy = Mockery::mock();
        $proxy->shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $value) use ($domain) {
                return $key === "proxy_cfg:{$domain->name}"
                    && $ttl === 3600
                    && is_array(json_decode($value, true));
            })
            ->andReturn(true);

        $pubsub = Mockery::mock();
        $pubsub->shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) use ($domain) {
                $decoded = json_decode($payload, true);

                return $channel === 'domain-config-updated'
                    && $decoded['host'] === $domain->name
                    && $decoded['action'] === 'pushed';
            })
            ->andReturn(1);

        Redis::shouldReceive('connection')->with('proxy')->andReturn($proxy);
        Redis::shouldReceive('connection')->with('pubsub')->andReturn($pubsub);

        app(DomainObserver::class)->forceBumpConfigVersion($domain);
    }

    public function test_domain_deletion_deletes_raw_proxy_cfg_key(): void
    {
        Queue::fake();

        $domain = Domain::factory()->create([
            'name' => 'delete-cache-test.example',
            'proxy_enabled' => true,
            'is_active' => true,
            'origin_url' => 'https://origin.example',
        ]);

        $proxy = Mockery::mock();
        $proxy->shouldReceive('del')
            ->once()
            ->with("proxy_cfg:{$domain->name}")
            ->andReturn(1);

        $pubsub = Mockery::mock();
        $pubsub->shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) use ($domain) {
                $decoded = json_decode($payload, true);

                return $channel === 'domain-config-updated'
                    && $decoded['host'] === $domain->name
                    && $decoded['action'] === 'invalidated';
            })
            ->andReturn(1);

        Redis::shouldReceive('connection')->with('proxy')->andReturn($proxy);
        Redis::shouldReceive('connection')->with('pubsub')->andReturn($pubsub);

        $domain->delete();
    }
}
