<?php

namespace App\Policies;

use App\Models\GroupInvitation;
use App\Models\User;
use App\Models\Group;
use Illuminate\Auth\Access\Response;

class GroupInvitationPolicy
{
    /**
     * Helper to check admin role
     */
    private function isAdmin(User $user, Group $group): bool
    {
        return $user->getTenantRole($group) === 'admin';
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $this->isAdmin($user, $tenant);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GroupInvitation $groupInvitation): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $groupInvitation->group_id && $this->isAdmin($user, $tenant);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $this->isAdmin($user, $tenant);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, GroupInvitation $groupInvitation): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $groupInvitation->group_id && $this->isAdmin($user, $tenant);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GroupInvitation $groupInvitation): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $groupInvitation->group_id && $this->isAdmin($user, $tenant);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GroupInvitation $groupInvitation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GroupInvitation $groupInvitation): bool
    {
        return false;
    }
}
