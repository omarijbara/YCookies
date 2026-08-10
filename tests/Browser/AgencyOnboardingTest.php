<?php

namespace Tests\Browser;

use Tests\DuskTestCase;
use Laravel\Dusk\Browser;
use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class AgencyOnboardingTest extends DuskTestCase
{
    /**
     * Test that an unverified agency is gated, and then test the Domain Setup Wizard.
     *
     * @return void
     */
    public function testAgencyWizardAndVerificationGating()
    {
        // 1. Create a fresh User and Group (Agency)
        $user = User::factory()->create([
            'email' => 'agency_' . time() . '@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => null // Unverified!
        ]);
        
        $group = Group::firstOrCreate(['name' => 'Dusk E2E Agency']);
        $user->groups()->attach($group->id, ['role' => 'admin']);

        $this->browse(function (Browser $browser) use ($user) {
            
            // 2. Login
            $browser->visit('/admin/login')
                    ->type('data.email', $user->email)
                    ->type('data.password', 'password123')
                    ->press('Sign in');

            // 3. Assert Email Verification Gating
            $browser->waitForText('Verify Your Email Address')
                    ->assertSee('Verify Your Email Address');

            // 4. Force Verify (Simulate clicking email link)
            $user->markEmailAsVerified();

            // 5. Navigate to Dashboard successfully now
            $browser->visit('/admin')
                    ->assertSee('Consent Acceptance Rates'); // Confirms successful access

            // 6. Test Domain Setup Wizard step-by-step
            $browser->visit('/admin/domains/create')
                    ->waitForText('Domain Info')
                    ->assertSee('Domain Info')
                    ->type('data.name', 'wizard-test-' . time() . '.com')
                    // Pressing next step in Filament Wizard
                    ->press('Next')
                    ->waitForText('Deployment Mode')
                    ->assertSee('Proxy vs Script Tag')
                    ->press('Next')
                    ->waitForText('Review & Finish')
                    ->assertSee('Deploy configuration')
                    ->press('Create');
                    
            $browser->waitForText('Domain') // Confirms resource was created and redirected to view/index
                    ->screenshot('agency_onboarding_wizard_success');
        });
    }
}
