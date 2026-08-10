<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Domain;
use Illuminate\Auth\Access\HandlesAuthorization;

class DomainPolicy
{
    use HandlesAuthorization;
    
    private function hasRole(User $user, array $allowedRoles): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        if (!$tenant) {
            return false;
        }

        $role = $user->getTenantRole($tenant);
        return in_array($role, $allowedRoles, true);
    }

    private function canView(User $user): bool
    {
        return $this->hasRole($user, ['admin', 'editor', 'viewer']);
    }

    private function canEdit(User $user): bool
    {
        return $this->hasRole($user, ['admin', 'editor']);
    }

    public function viewAny(User $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(User $authUser, Domain $domain): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $domain->group_id && $this->canView($authUser);
    }

    public function create(User $authUser): bool
    {
        return $this->canEdit($authUser);
    }

    public function update(User $authUser, Domain $domain): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $domain->group_id && $this->canEdit($authUser);
    }

    public function delete(User $authUser, Domain $domain): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $domain->group_id && $this->hasRole($authUser, ['admin']);
    }

    public function restore(User $authUser, Domain $domain): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Domain $domain): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Domain $domain): bool
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        return $tenant && $tenant->id === $domain->group_id && $this->canEdit($authUser);
    }

    public function reorder(User $authUser): bool
    {
        return $this->canEdit($authUser);
    }
}