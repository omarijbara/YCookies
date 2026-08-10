<?php

namespace Tests\Feature\Api;

use App\Models\ConsentLog;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsentIngestTest extends TestCase
{
    use RefreshDatabase;

    protected Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $group = Group::create(['name' => 'Test Agency']);
        $this->domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'ingest-test.com',
            'site_id' => Str::random(32),
            'is_active' => true,
            'consent_version' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ─── Valid Payloads ───

    public function test_logs_consent_with_full_payload(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'uid' => 'test_uid_12345678',
            'cookie_version' => 5,
            'consent' => [
                'type' => 'all',
                'groups' => ['essential' => true, 'analytics' => true, 'marketing' => true],
                'services' => ['google-analytics', 'facebook-pixel'],
            ],
            'region' => 'DE',
        ]);

        $response->assertStatus(200)->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('consent_logs', [
            'domain_id' => $this->domain->id,
            'consent_uid' => 'test_uid_12345678',
            'consent_type' => 'all',
            'cookie_version' => 5,
        ]);

        // Verify GDPR: IP hash is stored, not raw IP
        $log = ConsentLog::first();
        $this->assertNotNull($log->ip_hash);
        $this->assertNotEquals('127.0.0.1', $log->ip_hash);
        $this->assertEquals(64, strlen($log->ip_hash)); // SHA-256 = 64 hex chars
    }

    public function test_logs_consent_with_minimal_payload(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => [
                'type' => 'essential',
            ],
        ]);

        $response->assertStatus(200)->assertJson(['status' => 'ok']);

        $log = ConsentLog::first();
        $this->assertNotNull($log);
        $this->assertEquals('essential', $log->consent_type);
        $this->assertNotNull($log->consent_uid); // Auto-generated
    }

    public function test_logs_consent_uses_domain_version_when_not_provided(): void
    {
        $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => ['type' => 'all'],
        ]);

        $log = ConsentLog::first();
        $this->assertEquals(5, $log->cookie_version);
    }

    public function test_multiple_consents_from_same_uid_marks_previous_as_not_latest(): void
    {
        $payload = [
            'site_id' => $this->domain->site_id,
            'uid' => 'recurring_visitor',
            'consent' => ['type' => 'essential', 'groups' => [], 'services' => []],
        ];

        $this->postJson('/api/log-consent', $payload)->assertStatus(200);

        $payload['consent']['type'] = 'all';
        $this->postJson('/api/log-consent', $payload)->assertStatus(200);

        $logs = ConsentLog::where('consent_uid', 'recurring_visitor')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertFalse($logs[0]->is_latest);
        $this->assertTrue($logs[1]->is_latest);
    }

    // ─── Invalid Payloads ───

    public function test_rejects_missing_site_id(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'consent' => ['type' => 'all'],
        ]);

        $response->assertStatus(400)->assertJson(['status' => 'error']);
    }

    public function test_rejects_missing_consent(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
        ]);

        $response->assertStatus(400)->assertJson(['status' => 'error']);
    }

    public function test_returns_404_for_invalid_site_id(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'site_id' => 'nonexistent_site_id',
            'consent' => ['type' => 'all'],
        ]);

        $response->assertStatus(404);
    }

    public function test_returns_404_for_inactive_domain(): void
    {
        $this->domain->update(['is_active' => false]);
        Cache::flush();

        $response = $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => ['type' => 'all'],
        ]);

        $response->assertStatus(404);
    }

    public function test_rejects_empty_body(): void
    {
        $response = $this->postJson('/api/log-consent', []);

        $response->assertStatus(400);
    }

    // ─── Edge Cases ───

    public function test_handles_very_long_user_agent_by_truncating(): void
    {
        $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => ['type' => 'all'],
        ], ['User-Agent' => str_repeat('A', 1000)]);

        $log = ConsentLog::first();
        $this->assertLessThanOrEqual(500, strlen($log->user_agent));
    }

    public function test_invalidates_consent_stats_cache(): void
    {
        Cache::put("consent_stats:{$this->domain->id}", 'cached_data', 3600);

        $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => ['type' => 'all'],
        ]);

        $this->assertNull(Cache::get("consent_stats:{$this->domain->id}"));
    }

    // ─── XSS / Injection Protection ───

    public function test_sanitizes_consent_type_stored_in_database(): void
    {
        $response = $this->postJson('/api/log-consent', [
            'site_id' => $this->domain->site_id,
            'consent' => [
                'type' => '<script>alert("xss")</script>',
                'groups' => [],
                'services' => [],
            ],
        ]);

        // XSS attempt in consent.type is rejected by validation (not in allowed enum)
        $response->assertStatus(422);
        $this->assertNull(ConsentLog::first());
    }
}
