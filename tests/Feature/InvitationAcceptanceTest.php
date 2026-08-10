<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_email_can_accept_an_invitation_case_insensitively(): void
    {
        $group = Group::create(['name' => 'Agency Team']);
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'email' => 'invitee@example.com',
            'role' => 'editor',
        ]);
        $user = User::factory()->create([
            'email' => 'INVITEE@example.com',
        ]);

        $this->actingAs($user)
            ->get("/invitations/accept/{$invitation->token}")
            ->assertRedirect('/admin');

        $this->assertDatabaseHas('group_user', [
            'user_id' => $user->id,
            'group_id' => $group->id,
            'role' => 'editor',
        ]);
        $this->assertDatabaseMissing('group_invitations', [
            'id' => $invitation->id,
        ]);
    }

    public function test_mismatched_email_cannot_accept_an_invitation(): void
    {
        $group = Group::create(['name' => 'Agency Team']);
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'email' => 'invitee@example.com',
            'role' => 'viewer',
        ]);
        $user = User::factory()->create([
            'email' => 'other-user@example.com',
        ]);

        $this->actingAs($user)
            ->get("/invitations/accept/{$invitation->token}")
            ->assertRedirect('/')
            ->assertSessionHas('error', 'This invitation was sent to a different email address.');

        $this->assertDatabaseMissing('group_user', [
            'user_id' => $user->id,
            'group_id' => $group->id,
        ]);
        $this->assertDatabaseHas('group_invitations', [
            'id' => $invitation->id,
        ]);
    }
}
