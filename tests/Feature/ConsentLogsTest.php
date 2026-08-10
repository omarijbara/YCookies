<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Group;
use App\Models\Domain;
use App\Models\ConsentLog;
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Filament\Pages\ConsentLogs;

class ConsentLogsTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function can_render_consent_logs_page_and_export_action()
    {
        $group = Group::firstOrCreate(['name' => 'Default Agency']);
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'consent-test-'.uniqid().'.com',
            'site_id' => 'newuuid1234567890abcdef12345678',
            'is_active' => true,
            'origin_subdomain' => 'consent-test-'.uniqid(),
            'localization' => ['default_language' => 'en', 'auto_detect' => true, 'show_switcher' => true],
        ]);

        for ($i = 0; $i < 5; $i++) {
            ConsentLog::create([
                'domain_id' => $domain->id,
                'consent_uid' => 'uid_' . $i,
                'ip_hash' => 'hash_' . $i,
                'user_agent' => 'Mozilla/5.0',
                'consents_granted' => ['marketing' => true, 'analytics' => false],
                'services_granted' => [],
                'consent_type' => 'custom',
                'cookie_version' => 1,
                'is_latest' => true,
            ]);
        }
        
        $this->actingAs($user);
        Filament::setTenant($group);

        Livewire::test(ConsentLogs::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(ConsentLog::all())
            ->assertTableActionExists('export');
    }
}
