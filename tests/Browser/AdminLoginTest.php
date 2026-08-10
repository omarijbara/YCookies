<?php

namespace Tests\Browser;

use Tests\DuskTestCase;
use Laravel\Dusk\Browser;
use App\Models\User;
use App\Models\Domain;
use App\Models\CookieConsentLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class AdminLoginTest extends DuskTestCase
{
    public function testAdminLoginAndDashboard()
    {
        $domain = Domain::first();
        if ($domain) {
            CookieConsentLog::factory()->count(15)->create([
                'domain_id' => $domain->id,
                'consent_data' => ['type' => 'all'],
            ]);
            CookieConsentLog::factory()->count(5)->create([
                'domain_id' => $domain->id,
                'consent_data' => ['type' => 'essential'],
            ]);
            CookieConsentLog::factory()->count(2)->create([
                'domain_id' => $domain->id,
                'consent_data' => ['type' => 'custom'],
            ]);
        }

        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                    ->assertSee('Sign in')
                    ->type('data.email', env('DUSK_ADMIN_EMAIL', 'admin@ycookies.local'))
                    ->type('data.password', env('DUSK_ADMIN_PASSWORD', 'password'))
                    ->press('Sign in')
                    ->waitForRoute('filament.admin.pages.dashboard', [], 5)
                    ->assertSee('Consent Acceptance Rates');

            $browser->screenshot('dashboard_with_consent_chart');
        });
    }
}
