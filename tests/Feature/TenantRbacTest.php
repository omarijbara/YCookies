<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TenantRbacTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_admins_can_view_and_edit_their_own_domains()
    {
        $group = Group::factory()->create();
        $admin = User::factory()->create();
        $group->users()->attach($admin, ['role' => 'admin']);

        $domain = Domain::factory()->create(['group_id' => $group->id]);

        $this->actingAs($admin);
        
        // Simulating Filament tenancy context manually or through authorize
        // Filament policies usually take the model and the user.
        // We will call the Gate or Policy explicitly since Filament does this under the hood.
        \Filament\Facades\Filament::setTenant($group);

        $this->assertTrue($admin->can('view', $domain));
        $this->assertTrue($admin->can('update', $domain));
        $this->assertTrue($admin->can('delete', $domain));
    }

    #[Test]
    public function tenant_editors_can_view_and_edit_but_not_delete_domains()
    {
        $group = Group::factory()->create();
        $editor = User::factory()->create();
        $group->users()->attach($editor, ['role' => 'editor']);

        $domain = Domain::factory()->create(['group_id' => $group->id]);

        $this->actingAs($editor);
        \Filament\Facades\Filament::setTenant($group);

        $this->assertTrue($editor->can('view', $domain));
        $this->assertTrue($editor->can('update', $domain));
        $this->assertFalse($editor->can('delete', $domain));
    }

    #[Test]
    public function tenant_viewers_can_only_view_domains()
    {
        $group = Group::factory()->create();
        $viewer = User::factory()->create();
        $group->users()->attach($viewer, ['role' => 'viewer']);

        $domain = Domain::factory()->create(['group_id' => $group->id]);

        $this->actingAs($viewer);
        \Filament\Facades\Filament::setTenant($group);

        $this->assertTrue($viewer->can('view', $domain));
        $this->assertFalse($viewer->can('update', $domain));
        $this->assertFalse($viewer->can('delete', $domain));
    }

    #[Test]
    public function users_cannot_access_domains_from_other_tenants()
    {
        $group1 = Group::factory()->create();
        $admin1 = User::factory()->create();
        $group1->users()->attach($admin1, ['role' => 'admin']);
        $domain1 = Domain::factory()->create(['group_id' => $group1->id]);

        $group2 = Group::factory()->create();
        $admin2 = User::factory()->create();
        $group2->users()->attach($admin2, ['role' => 'admin']);
        $domain2 = Domain::factory()->create(['group_id' => $group2->id]);

        $this->actingAs($admin1);
        \Filament\Facades\Filament::setTenant($group1);

        $this->assertTrue($admin1->can('view', $domain1));
        $this->assertFalse($admin1->can('view', $domain2));
        $this->assertFalse($admin1->can('update', $domain2));
    }
}
