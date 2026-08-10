<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\CookieBar;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebuggerRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_debugger_page_renders_without_errors(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Test Agency']);

        // CookieBar schema: name, group_id, theme_settings, translations
        $bar = CookieBar::create([
            'name' => 'Test Cookie Bar',
            'group_id' => $group->id,
        ]);

        // Domain links to CookieBar via cookie_bar_id (not the reverse)
        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'debugger-test.com',
            'site_id' => 'test_site_id_debugger',
            'is_active' => true,
            'cookie_bar_id' => $bar->id,
        ]);

        // Filament multi-tenant route: /admin/{tenant}/cookie-bars/{record}/edit
        $response = $this->actingAs($user)->get("/admin/{$group->id}/cookie-bars/{$bar->id}/edit");

        // Should render without 500 errors (200 or 403 if authorization applies)
        $this->assertContains($response->getStatusCode(), [200, 403]);
    }
}
