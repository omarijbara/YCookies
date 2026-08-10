<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Domain;
use App\Models\Group;

class E2ETestSeeder extends Seeder
{
    public function run()
    {
        // 1. Get the group created by AdminUserSeeder
        $group = Group::first();
        if (!$group) {
            $group = Group::create(['name' => 'Default Agency', 'description' => 'E2E Testing Group']);
        }

        // 2. Create local test domains
        $d1 = Domain::firstOrCreate(
            ['name' => 'ycookies.test'],
            ['site_id' => 'site-localtest', 'group_id' => $group->id]
        );
        $d1->update([
            'site_id' => 'site-localtest',
            'group_id' => $group->id,
            'is_active' => true,
            'proxy_enabled' => true,
            'proxy_status' => 'active',
            'origin_url' => 'https://origin.ycookies.test',
            'origin_host' => 'origin.ycookies.test',
            'origin_auth_token' => 'playwright-origin-token',
            'config_version' => 1,
        ]);

        $d2 = Domain::firstOrCreate(
            ['name' => 'canary2.test'],
            ['site_id' => 'site-canary2', 'group_id' => $group->id]
        );
        $d2->update([
            'site_id' => 'site-canary2',
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        echo "Domains created successfully.\n";

        // Call the main seeder to populate services/groups for these domains
        $this->call([
            DatabaseSeeder::class,
        ]);
    }
}
