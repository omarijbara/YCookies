<?php

namespace Tests\Feature;

use App\Models\CookieBar;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CookieBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_cookie_bar_and_assign_to_a_domain(): void
    {
        $cookieBar = CookieBar::create([
            'name' => 'Default Theme',
            'theme_settings' => [
                'primary_color' => '#ff0000',
            ],
            'translations' => [
                'banner_title' => 'Test Banner',
            ],
        ]);

        $domain = Domain::create([
            'name' => 'example.com',
            'site_id' => '12345678901234567890123456789012',
            'cookie_bar_id' => $cookieBar->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('cookie_bars', [
            'name' => 'Default Theme',
        ]);

        $this->assertDatabaseHas('domains', [
            'name' => 'example.com',
            'cookie_bar_id' => $cookieBar->id,
        ]);

        $this->assertEquals('Default Theme', $domain->fresh()->cookieBar->name);
        $this->assertEquals('#ff0000', $domain->fresh()->cookieBar->theme_settings['primary_color']);
    }
}
