<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Group;
use App\Models\ScriptBlocker;
use App\Services\ScriptScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanDomainTest extends TestCase
{
    use RefreshDatabase;

    protected Group $group;
    protected Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = Group::create(['name' => 'Agency A']);
        $this->domain = Domain::create([
            'group_id' => $this->group->id,
            'name' => 'scan-target.com',
            'site_id' => '1234567890abcdef1234567890abcdef',
            'is_active' => true,
        ]);
    }

    /**
     * Test that ScriptScannerService::categorize() correctly classifies
     * external script URLs into 'suggested' (from template library)
     * and 'unknown' buckets.
     */
    public function test_categorize_matches_known_scripts_from_template_library(): void
    {
        // Simulate external script URLs that would be discovered during a scan
        $scriptUrls = [
            'https://www.googletagmanager.com/gtag/js?id=G-XXXXX',   // Google Analytics (template match)
            'https://connect.facebook.net/en_US/fbevents.js',          // Facebook/Meta Pixel (template match)
            'https://www.youtube.com/iframe_api',                       // YouTube (template match)
            'https://random-unknown-tracker.io/pixel.js',               // Unknown script
        ];

        $results = ScriptScannerService::categorize($this->domain, $scriptUrls);

        // Verify structure
        $this->assertArrayHasKey('protected', $results);
        $this->assertArrayHasKey('suggested', $results);
        $this->assertArrayHasKey('unknown', $results);
        $this->assertArrayHasKey('raw', $results);

        // Raw should contain all input URLs
        $this->assertCount(4, $results['raw']);

        // At least some known scripts should be in 'suggested' (matched from template library)
        $suggestedKeys = array_column($results['suggested'], 'template_key');
        $this->assertNotEmpty($suggestedKeys, 'At least one known script should be matched from the template library');

        // Unknown tracker should appear in 'unknown'
        $unknownHosts = array_column($results['unknown'], 'host');
        $this->assertContains('random-unknown-tracker.io', $unknownHosts);
    }

    /**
     * Test that installed script blockers are categorized as 'protected'.
     */
    public function test_categorize_marks_installed_blockers_as_protected(): void
    {
        // Install a script blocker for this domain
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'name' => 'Google Analytics',
            'key' => 'google-analytics',
            'phrases' => ['googletagmanager.com/gtag', 'google-analytics.com'],
            'is_active' => true,
        ]);

        $scriptUrls = [
            'https://www.googletagmanager.com/gtag/js?id=G-XXXXX',
            'https://random-unknown-tracker.io/pixel.js',
        ];

        $results = ScriptScannerService::categorize($this->domain, $scriptUrls);

        // Google Analytics URL should be 'protected' (installed blocker matches)
        $this->assertNotEmpty($results['protected']);
        $protectedKeys = array_column($results['protected'], 'blocker_key');
        $this->assertContains('google-analytics', $protectedKeys);

        // Unknown tracker is still unknown
        $this->assertNotEmpty($results['unknown']);
    }

    /**
     * Test that an empty URL list returns empty categorization.
     */
    public function test_categorize_handles_empty_url_list(): void
    {
        $results = ScriptScannerService::categorize($this->domain, []);

        $this->assertEmpty($results['protected']);
        $this->assertEmpty($results['suggested']);
        $this->assertEmpty($results['unknown']);
        $this->assertEmpty($results['raw']);
    }

    /**
     * Test that categorize handles null domain gracefully.
     */
    public function test_categorize_handles_null_domain(): void
    {
        $scriptUrls = [
            'https://www.googletagmanager.com/gtag/js?id=G-XXXXX',
            'https://random-unknown-tracker.io/pixel.js',
        ];

        $results = ScriptScannerService::categorize(null, $scriptUrls);

        // Should still categorize against template library, just no 'protected' entries
        $this->assertEmpty($results['protected']);
        $this->assertNotEmpty($results['suggested'] + $results['unknown']);
    }
}
