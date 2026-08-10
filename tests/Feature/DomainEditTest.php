<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_domain_page_loads_without_fatal_error()
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Test Agency']);

        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'domain-edit-test.com',
            'site_id' => 'test_site_id_domain_edit',
            'is_active' => true,
        ]);

        // Filament multi-tenant route: /admin/{tenant}/domains/{record}/edit
        $url = "/admin/{$group->id}/domains/{$domain->id}/edit";

        $response = $this->actingAs($user)->get($url);

        // Should render without 500 errors (200 or 403 if authorization applies)
        $this->assertContains($response->getStatusCode(), [200, 403]);
    }
}
