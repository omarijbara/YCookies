<?php

namespace Tests\Feature;

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DomainProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_domain_and_validates_redis_cache()
    {
        // Create a mock that handles both connection() and direct method calls
        $redisMock = \Mockery::mock();
        $redisMock->shouldReceive('setex')->andReturn(true);
        $redisMock->shouldReceive('get')->andReturn('{"mock":"json"}');
        $redisMock->shouldReceive('publish')->andReturn(1);

        Redis::shouldReceive('connection')->with('proxy')->andReturn($redisMock);
        Redis::shouldReceive('connection')->with('pubsub')->andReturn($redisMock);

        $this->artisan('domain:provision', ['name' => 'duftz.de'])
             ->expectsOutputToContain('provisioning')
             ->assertSuccessful();

        $domain = Domain::where('name', 'duftz.de')->first();
        
        $this->assertNotNull($domain);
        $this->assertTrue($domain->proxy_enabled);
        $this->assertEquals('active', $domain->proxy_status);
        $this->assertEquals(1, $domain->site_id);
        $this->assertNotNull($domain->origin_auth_token);
    }
}
