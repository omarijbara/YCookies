<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Provider;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProviderPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Provider');
    }

    public function view(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('View:Provider');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Provider');
    }

    public function update(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('Update:Provider');
    }

    public function delete(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('Delete:Provider');
    }

    public function restore(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('Restore:Provider');
    }

    public function forceDelete(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('ForceDelete:Provider');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Provider');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Provider');
    }

    public function replicate(AuthUser $authUser, Provider $provider): bool
    {
        return $authUser->can('Replicate:Provider');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Provider');
    }

}