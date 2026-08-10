<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $domain = \App\Models\Domain::first();
        if ($domain) {
            // Create Essential Groups
            $statsGroup = \App\Models\CookieGroup::firstOrCreate(
                ['key' => 'statistics'],
                ['name' => 'Statistics', 'sort_order' => 1]
            );
            $domain->cookieGroups()->syncWithoutDetaching([$statsGroup->id]);

            $marketingGroup = \App\Models\CookieGroup::firstOrCreate(
                ['key' => 'marketing'],
                ['name' => 'Marketing', 'sort_order' => 2]
            );
            $domain->cookieGroups()->syncWithoutDetaching([$marketingGroup->id]);

            // Create Mock Provider
            $mockProvider = \App\Models\Provider::firstOrCreate(
                ['name' => 'Mock Provider'],
                [
                    'address' => '123 Test Street, Developer City',
                    'privacy_policy_url' => 'https://example.com/privacy',
                ]
            );

            // Create Mock Analytics Service
            $mockService = \App\Models\Service::firstOrCreate(
                ['key' => 'mock_analytics'],
                [
                    'name' => 'Mock Analytics',
                    'provider_id' => $mockProvider->id,
                    'cookie_group_id' => $statsGroup->id,
                    'sort_order' => 1,
                ]
            );
            $domain->services()->syncWithoutDetaching([$mockService->id]);

            // Create Mock Cookie
            \App\Models\ServiceCookie::firstOrCreate(
                [
                    'service_id' => $mockService->id,
                    'name' => '_mock_ga'
                ],
                [
                    'hostname' => '.example.com',
                    'lifetime' => '2 Years',
                    'purpose' => 'Used to distinguish users.'
                ]
            );

            // Create YouTube Content Blocker
            if (!\App\Models\ContentBlocker::where('key', 'youtube')->exists()) {
                $youtubeBlocker = \App\Models\ContentBlocker::create([
                    'key' => 'youtube',
                    'name' => 'YouTube',
                    'hosts' => ['youtube.com', 'youtu.be'],
                    'is_active' => true,
                    'domain_id' => $domain->id,
                ]);
            }
        }

        $this->call([
            AdminUserSeeder::class,
            LanguageSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
