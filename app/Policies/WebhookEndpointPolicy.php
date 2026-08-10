<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Auth\Access\HandlesAuthorization;

class WebhookEndpointPolicy
{
    use HandlesAuthorization;

    private function tenantGroup(): ?Group
    {
        $tenant = \Filament\Facades\Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }

    private function hasRole(User $user, array $allowedRoles): bool
    {
        $tenant = $this->tenantGroup();
        if (! $tenant) {
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

    public function view(User $authUser, WebhookEndpoint $record): bool
    {
        $tenant = $this->tenantGroup();

        return $tenant && $tenant->id === $record->group_id && $this->canView($authUser);
    }

    public function create(User $authUser): bool
    {
        return $this->canEdit($authUser);
    }

    public function update(User $authUser, WebhookEndpoint $record): bool
    {
        $tenant = $this->tenantGroup();

        return $tenant && $tenant->id === $record->group_id && $this->canEdit($authUser);
    }

    public function delete(User $authUser, WebhookEndpoint $record): bool
    {
        $tenant = $this->tenantGroup();

        return $tenant && $tenant->id === $record->group_id && $this->hasRole($authUser, ['admin']);
    }

    public function restore(User $authUser, WebhookEndpoint $record): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, WebhookEndpoint $record): bool
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

    public function replicate(User $authUser, WebhookEndpoint $record): bool
    {
        $tenant = $this->tenantGroup();

        return $tenant && $tenant->id === $record->group_id && $this->canEdit($authUser);
    }

    public function reorder(User $authUser): bool
    {
        return $this->canEdit($authUser);
    }
}
