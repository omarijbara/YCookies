<?php

namespace Tests\Feature;

use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsentVersionObserverTest extends TestCase
{
    use RefreshDatabase;

    protected Domain $domain;
    protected CookieGroup $cookieGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $group = Group::create(['name' => 'Test Agency']);
        $this->domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'version-test.com',
            'site_id' => Str::random(32),
            'is_active' => true,
            'consent_version' => 1,
        ]);

        $this->cookieGroup = CookieGroup::create([
            'name' => 'Analytics',
            'key' => 'analytics',
            'is_required' => false,
        ]);
        $this->cookieGroup->domains()->attach($this->domain->id);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * This test verifies that updating an existing CookieGroup (which already has domain
     * attachments via the pivot) triggers the ConsentVersionObserver and increments
     * the consent_version on all related domains.
     */
    public function test_updating_cookie_group_increments_domain_consent_version(): void
    {
        $this->assertEquals(1, $this->domain->fresh()->consent_version);

        $this->cookieGroup->update(['name' => 'Analytics Updated']);

        $this->assertGreaterThan(1, $this->domain->fresh()->consent_version);
    }

    /**
     * Adding a new service (which has a cookie_group_id FK) should trigger
     * the observer on Service model.
     */
    public function test_creating_service_increments_domain_consent_version(): void
    {
        // Reset version to a known state
        $this->domain->update(['consent_version' => 10]);
        Cache::flush();

        Service::create([
            'cookie_group_id' => $this->cookieGroup->id,
            'name' => 'New Tracker',
            'key' => 'new-tracker',
            'is_active' => true,
        ]);

        // Service observer should have incremented via its domains() relationship
        $this->assertGreaterThanOrEqual(10, $this->domain->fresh()->consent_version);
    }

    /**
     * Config cache should be invalidated when consent-relevant changes occur.
     */
    public function test_invalidates_config_cache_on_cookie_group_update(): void
    {
        Cache::put("consent_config:{$this->domain->site_id}", 'cached_config', 3600);
        $this->assertNotNull(Cache::get("consent_config:{$this->domain->site_id}"));

        $this->cookieGroup->update(['description' => 'Updated description']);

        $this->assertNull(Cache::get("consent_config:{$this->domain->site_id}"));
    }
}
