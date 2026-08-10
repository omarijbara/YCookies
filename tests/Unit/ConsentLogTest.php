<?php

namespace Tests\Unit;

use App\Models\ConsentLog;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsentLogTest extends TestCase
{
    use RefreshDatabase;

    protected Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $group = Group::create(['name' => 'Test Agency']);
        $this->domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'test-consent.com',
            'site_id' => Str::random(32),
            'is_active' => true,
            'consent_version' => 1,
        ]);
    }

    // ─── is_latest Tracking ───

    public function test_first_log_for_uid_is_marked_as_latest(): void
    {
        $log = ConsentLog::create([
            'domain_id' => $this->domain->id,
            'consent_uid' => 'uid_aaa',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'consent_type' => 'all',
            'cookie_version' => 1,
            'consents_granted' => ['essential' => true, 'analytics' => true],
            'services_granted' => ['google-analytics'],
        ]);

        $this->assertTrue($log->fresh()->is_latest);
    }

    public function test_new_log_for_same_uid_marks_previous_as_not_latest(): void
    {
        $first = ConsentLog::create([
            'domain_id' => $this->domain->id,
            'consent_uid' => 'uid_bbb',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'consent_type' => 'all',
            'cookie_version' => 1,
            'consents_granted' => ['essential' => true],
            'services_granted' => [],
        ]);

        $second = ConsentLog::create([
            'domain_id' => $this->domain->id,
            'consent_uid' => 'uid_bbb',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'consent_type' => 'custom',
            'cookie_version' => 2,
            'consents_granted' => ['essential' => true, 'marketing' => true],
            'services_granted' => ['facebook-pixel'],
        ]);

        $this->assertFalse($first->fresh()->is_latest);
        $this->assertTrue($second->fresh()->is_latest);
    }

    public function test_different_uids_maintain_independent_latest_flags(): void
    {
        $logA = ConsentLog::create([
            'domain_id' => $this->domain->id,
            'consent_uid' => 'uid_xxx',
            'ip_hash' => hash('sha256', '1.1.1.1'),
            'consent_type' => 'all',
            'cookie_version' => 1,
            'consents_granted' => ['essential' => true],
            'services_granted' => [],
        ]);

        $logB = ConsentLog::create([
            'domain_id' => $this->domain->id,
            'consent_uid' => 'uid_yyy',
            'ip_hash' => hash('sha256', '2.2.2.2'),
            'consent_type' => 'essential',
            'cookie_version' => 1,
            'consents_granted' => ['essential' => true],
            'services_granted' => [],
        ]);

        // Both should be latest since they are different UIDs
        $this->assertTrue($logA->fresh()->is_latest);
        $this->assertTrue($logB->fresh()->is_latest);
    }

    // ─── Scopes ───

    public function test_latest_consent_scope_filters_correctly(): void
    {
        // Create 3 entries for same UID — only last should be "latest"
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'scope_test', 'ip_hash' => 'h1', 'consent_type' => 'all', 'cookie_version' => 1, 'consents_granted' => [], 'services_granted' => []]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'scope_test', 'ip_hash' => 'h2', 'consent_type' => 'custom', 'cookie_version' => 2, 'consents_granted' => [], 'services_granted' => []]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'scope_test', 'ip_hash' => 'h3', 'consent_type' => 'essential', 'cookie_version' => 3, 'consents_granted' => [], 'services_granted' => []]);

        $latestOnly = ConsentLog::latestConsent()->where('consent_uid', 'scope_test')->get();

        $this->assertCount(1, $latestOnly);
        $this->assertEquals('essential', $latestOnly->first()->consent_type);
    }

    public function test_for_version_scope_filters_by_cookie_version(): void
    {
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'v1', 'ip_hash' => 'h', 'consent_type' => 'all', 'cookie_version' => 1, 'consents_granted' => [], 'services_granted' => []]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'v2', 'ip_hash' => 'h', 'consent_type' => 'all', 'cookie_version' => 2, 'consents_granted' => [], 'services_granted' => []]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'v3', 'ip_hash' => 'h', 'consent_type' => 'all', 'cookie_version' => 2, 'consents_granted' => [], 'services_granted' => []]);

        $this->assertCount(1, ConsentLog::forVersion(1)->get());
        $this->assertCount(2, ConsentLog::forVersion(2)->get());
    }

    // ─── History ───

    public function test_get_history_returns_entries_in_reverse_chronological_order(): void
    {
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'hist_uid', 'ip_hash' => 'h1', 'consent_type' => 'essential', 'cookie_version' => 1, 'consents_granted' => [], 'services_granted' => [], 'created_at' => now()->subMinutes(10)]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'hist_uid', 'ip_hash' => 'h2', 'consent_type' => 'all', 'cookie_version' => 2, 'consents_granted' => [], 'services_granted' => [], 'created_at' => now()]);

        $history = ConsentLog::getHistory('hist_uid', $this->domain->id);

        $this->assertCount(2, $history);
        $this->assertEquals('all', $history->first()->consent_type); // Most recent first
    }

    // ─── Cleanup ───

    public function test_cleanup_removes_logs_older_than_specified_days(): void
    {
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'old', 'ip_hash' => 'h', 'consent_type' => 'all', 'cookie_version' => 1, 'consents_granted' => [], 'services_granted' => [], 'created_at' => now()->subDays(400)]);
        ConsentLog::create(['domain_id' => $this->domain->id, 'consent_uid' => 'new', 'ip_hash' => 'h', 'consent_type' => 'all', 'cookie_version' => 1, 'consents_granted' => [], 'services_granted' => [], 'created_at' => now()]);

        $deleted = ConsentLog::cleanUp(365);

        $this->assertEquals(1, $deleted);
        $this->assertCount(1, ConsentLog::all());
        $this->assertEquals('new', ConsentLog::first()->consent_uid);
    }
}
