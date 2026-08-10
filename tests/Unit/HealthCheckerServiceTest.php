<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Services\HealthCheckerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckerServiceTest extends TestCase
{
    public function test_config_endpoint_probe_is_signed_when_proxy_secret_is_configured(): void
    {
        config([
            'app.url' => 'https://admin.ycookies.test',
            'services.proxy.shared_secret' => 'health-proxy-secret',
        ]);

        Http::fake([
            'https://admin.ycookies.test/api/proxy-config/*' => Http::response([
                'domain' => 'signed-health.test',
            ], 200),
        ]);

        $domain = new Domain([
            'name' => 'signed-health.test',
        ]);

        $service = new class extends HealthCheckerService
        {
            public function checkConfigEndpointPublic(Domain $domain): array
            {
                return $this->checkConfigEndpoint($domain);
            }
        };

        $result = $service->checkConfigEndpointPublic($domain);

        $this->assertSame('pass', $result['status']);

        Http::assertSent(function (Request $request) use ($domain) {
            $expectedSignature = hash_hmac('sha256', strtolower($domain->name), 'health-proxy-secret');

            return $request->url() === 'https://admin.ycookies.test/api/proxy-config/' . $domain->name
                && $request->hasHeader('X-Proxy-Signature', $expectedSignature);
        });
    }


}
