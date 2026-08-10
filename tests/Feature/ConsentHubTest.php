<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentHubTest extends TestCase
{
    use RefreshDatabase;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->group = Group::create(['name' => 'Agency A']);
    }

    public function test_hub_returns_403_for_invalid_site_id()
    {
        $response = $this->get('/api/hub/invalid_site_id');
        $response->assertStatus(403);
    }

    public function test_hub_returns_403_if_cross_domain_is_disabled()
    {
        $domain = Domain::create([
            'group_id' => $this->group->id,
            'name' => 'primary.com',
            'site_id' => '1234567890abcdef1234567890abcdef',
            'cross_domain_enabled' => false,
            'is_active' => true,
        ]);

        $response = $this->get('/api/hub/' . $domain->site_id);
        
        $response->assertStatus(403);
        $response->assertSee('Cross-domain consent is heavily restricted');
    }

    public function test_hub_returns_valid_iframe_code_when_authorized()
    {
        $domainPrimary = Domain::create([
            'group_id' => $this->group->id,
            'name' => 'primary.com',
            'site_id' => '1234567890abcdef1234567890abcdef',
            'cross_domain_enabled' => true,
            'is_active' => true,
        ]);

        $domainSecondary = Domain::create([
            'group_id' => $this->group->id,
            'name' => 'secondary.com',
            'site_id' => 'abcdef1234567890abcdef1234567890',
            'cross_domain_enabled' => true, 
            'is_active' => true,
        ]);

        $responseAuthorized = $this->get('/api/hub/' . $domainPrimary->site_id);

        $responseAuthorized->assertStatus(200);
        // The Blade view must output the allowed origins in the HTML.
        $responseAuthorized->assertSee('primary.com');
        $responseAuthorized->assertSee('secondary.com');
    }
}
