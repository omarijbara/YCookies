<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use App\Models\Domain;

class BlockerTest extends DuskTestCase
{
    /**
     * Test the YCookies banner handles script and content blockers correctly.
     */
    public function test_script_and_content_blockers(): void
    {
        // Find existing domain from seeder or create one if none exists in Dusk DB
        $domain = Domain::first();
        if (!$domain) {
            $this->markTestSkipped('No domain seeded for Dusk to test against.');
        }

        $this->browse(function (Browser $browser) use ($domain) {
            
            // Clear cookies before starting
            $browser->driver->manage()->deleteAllCookies();
            
            // Go to the test-client page
            $browser->visit('/test-client')
                    ->pause(1500) // Wait for the manager.js to fetch config and render banner
                    
                    // 1. Verify Banner Appears
                    ->assertPresent('#yc-banner-container')
                    ->assertSee('Wir verwenden Cookies')
                    
                    // 2. Verify Initial Blocked State
                    ->assertSeeIn('#analytics-status', 'Not Loaded') // Analytics script blocked
                    ->assertSeeIn('#gtm-status', 'Not Loaded') // GTM script blocked
                    
                    // The YouTube iframe should be replaced by the custom content blocker overlay
                    ->assertPresent('.yc-content-blocker-overlay')
                    ->assertMissing('iframe[src*="youtube.com/embed"]') // Actual iframe shouldn't exist
                    ->assertSee('Video laden') // Borlabs-style Content Blocker button text
                    
                    // 3. Accept All Cookies
                    ->click('#yc-accept-all-btn')
                    ->pause(1000) // Wait for consent to process and page to react
                    
                    // 4. Verify Final Unblocked State
                    // The banner should be gone
                    ->assertMissing('#yc-banner-container')
                    
                    // The script blockers should have released the scripts
                    ->assertSeeIn('#analytics-status', 'Loaded')
                    ->assertSeeIn('#gtm-status', 'Loaded (GTM Initialized)')
                    
                    // The content blocker should have loaded the iframe
                    ->assertMissing('.yc-content-blocker-overlay')
                    ->assertPresent('iframe[src*="youtube.com/embed"]')
                    
                    // Check if DataLayer logged the events (Advanced GTM test)
                    ->assertSeeIn('#datalayer-log', 'ycookies_consent_statistics')
                    ->assertSeeIn('#datalayer-log', 'ycookies_consent_marketing');
        });
    }
}
