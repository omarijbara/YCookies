<?php

namespace Tests\Feature;

use App\Models\ConsentLog;
use App\Models\Domain;
use App\Models\Group;
use App\Models\User;
use App\Services\GdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GdprServiceTest extends TestCase
{
    use RefreshDatabase;

    private GdprService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GdprService();
        Storage::fake('local');
    }

    /** @test */
    public function it_can_export_group_data()
    {
        $group = Group::factory()->create(['name' => 'Test Group']);
        $domain = Domain::factory()->for($group)->create(['name' => 'example.com']);
        ConsentLog::factory()->create(['domain_id' => $domain->id]);

        $path = $this->service->exportGroup($group);

        $this->assertFileExists($path);
        
        $content = json_decode(file_get_contents($path), true);
        $this->assertEquals('Test Group', $content['group']['name']);
        $this->assertEquals(1, $content['consent_logs_summary']['total_count']);
        $this->assertArrayHasKey('exported_at', $content);
    }

    /** @test */
    public function it_can_delete_a_group_and_all_associated_data()
    {
        // 1. Setup complex relationships
        $group = Group::factory()->create();
        $user = User::factory()->create();
        $otherGroup = Group::factory()->create();
        
        $group->users()->attach($user);
        $otherGroup->users()->attach($user); // User has another group, should not be deleted

        $domain = Domain::factory()->for($group)->create(['name' => 'to-delete.com']);
        $otherDomain = Domain::factory()->for($otherGroup)->create(['name' => 'keep.me']);
        
        ConsentLog::factory()->count(3)->create(['domain_id' => $domain->id]);
        ConsentLog::factory()->count(1)->create(['domain_id' => $otherDomain->id]);

        // 2. Perform deletion
        $this->service->deleteGroup($group);

        // 3. Verify deletions
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        $this->assertDatabaseHas('groups', ['id' => $otherGroup->id]);

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertDatabaseHas('domains', ['id' => $otherDomain->id]);

        $this->assertDatabaseCount('consent_logs', 1);
        $this->assertEquals(0, ConsentLog::where('domain_id', $domain->id)->count());

        // Verify User management
        $this->assertDatabaseHas('users', ['id' => $user->id]); // Still has otherGroup
        $this->assertDatabaseMissing('group_user', ['group_id' => $group->id, 'user_id' => $user->id]);
    }

    /** @test */
    public function it_deletes_user_if_they_have_no_other_groups()
    {
        $group = Group::factory()->create();
        $user = User::factory()->create();
        $group->users()->attach($user);

        $this->assertEquals(1, $user->groups()->count());

        $this->service->deleteGroup($group);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
