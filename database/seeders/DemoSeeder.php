<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Domain;

class DemoSeeder extends Seeder
{
    public function run()
    {
        $domain = Domain::first();
        if ($domain && $domain->cookieGroups()->count() === 0) {
            $ess = $domain->cookieGroups()->create(['key' => 'essential', 'name' => 'Essential', 'description' => 'Essential services enable basic functions and are necessary for the proper functioning of the website.', 'is_required' => true, 'sort_order' => 1]);
            $stat = $domain->cookieGroups()->create(['key' => 'statistics', 'name' => 'Statistics', 'description' => 'Statistics cookies collect information anonymously. This information helps us to understand how our visitors use our website.', 'is_required' => false, 'sort_order' => 2]);
            $mark = $domain->cookieGroups()->create(['key' => 'marketing', 'name' => 'Marketing', 'description' => 'Marketing cookies are used by third-party advertisers or publishers to display personalized ads.', 'is_required' => false, 'sort_order' => 3]);

            $ess->services()->create(['key' => 'ycookies', 'name' => 'YCookies Consent', 'provider' => 'Local', 'purpose' => 'Stores the user\'s cookie consent state', 'is_active' => true, 'sort_order' => 1, 'cookie_names' => ['ycookies_consent']]);
            $stat->services()->create(['key' => 'google_analytics', 'name' => 'Google Analytics', 'provider' => 'Google Ireland Limited', 'purpose' => 'Cookie by Google used for website analytics.', 'is_active' => true, 'sort_order' => 1, 'cookie_names' => ['_ga', '_gid']]);
            $mark->services()->create(['key' => 'meta_pixel', 'name' => 'Meta Pixel', 'provider' => 'Meta Platforms Ireland Ltd.', 'purpose' => 'Cookie by Meta used for website analytics, ad targeting, and ad measurement.', 'is_active' => true, 'sort_order' => 1, 'cookie_names' => ['_fbp']]);

            $this->command->info('Seeded demo groups.');
        } else {
            $this->command->info('Groups already exist or no domain.');
        }
    }
}
