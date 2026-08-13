<?php

namespace Tests\Feature;

use App\Filament\Pages\Developer;
use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel has open registration, so instance-level pages (platform
 * secrets, server process execution) must be gated to super_admin.
 * canAccess() is what Filament consults for both routing and navigation.
 */
class InstancePageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_users_cannot_access_settings_or_developer_pages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(Settings::canAccess(), 'Settings page must be closed to non-super-admins');
        $this->assertFalse(Developer::canAccess(), 'Developer page must be closed to non-super-admins');
    }

    public function test_guests_cannot_access_settings_or_developer_pages(): void
    {
        $this->assertFalse(Settings::canAccess());
        $this->assertFalse(Developer::canAccess());
    }

    public function test_super_admin_can_access_settings_and_developer_pages(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\AdminUserSeeder']);
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::where('email', 'admin@ycookies.local')->firstOrFail();
        $this->actingAs($admin);

        $this->assertTrue(Settings::canAccess());
        $this->assertTrue(Developer::canAccess());
    }
}
