<?php

namespace Tests\Feature\Api;

use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

class ConsentConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        parent::tearDown();
    }

    public function test_returns_complete_configuration_payload_for_valid_domain_including_enterprise_geo_and_versioning_data()
    {
        $group = Group::create(['name' => 'Agency A']);
        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'geo-test.com',
            'site_id' => Str::random(32),
            'is_active' => true,
            'geo_restriction_eu' => true,
            'consent_version' => 3,
            'ui_config' => ['colors' => ['primary' => '#ff0000']]
        ]);

        $response = $this->withHeaders([
            'CF-IPCountry' => 'DE' // Germany (EU)
        ])->getJson("/api/config/{$domain->site_id}");

        if ($response->status() !== 200) {
            $json = $response->json();
            dd('API ERROR: ' . ($json['message'] ?? 'Unknown'), 'File: ' . ($json['file'] ?? ''), 'Line: ' . ($json['line'] ?? ''));
        }

        $response->assertStatus(200)
                 ->assertJson([
                     'site_id' => $domain->site_id,
                     'geo_restriction_eu' => true,
                     'consent_version' => 3,
                     'visitor_country' => 'DE',
                 ]);
                 
        $this->assertEquals('#ff0000', $response->json('ui_config.colors.primary'));
    }

    public function test_defaults_visitor_country_to_null_if_cloudflare_header_is_missing()
    {
        $group = Group::create(['name' => 'Agency A']);
        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'geo-test-2.com',
            'site_id' => Str::random(32),
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/config/{$domain->site_id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('visitor_country'));
    }

    public function test_returns_404_for_inactive_domains()
    {
        $group = Group::create(['name' => 'Agency A']);
        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'geo-test-3.com',
            'site_id' => Str::random(32),
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/config/{$domain->site_id}");

        $response->assertStatus(404);
    }
}
