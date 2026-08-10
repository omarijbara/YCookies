<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ScriptBlocker;
use Illuminate\Auth\Access\HandlesAuthorization;

class ScriptBlockerPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ScriptBlocker');
    }

    public function view(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('View:ScriptBlocker');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ScriptBlocker');
    }

    public function update(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('Update:ScriptBlocker');
    }

    public function delete(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('Delete:ScriptBlocker');
    }

    public function restore(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('Restore:ScriptBlocker');
    }

    public function forceDelete(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('ForceDelete:ScriptBlocker');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ScriptBlocker');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ScriptBlocker');
    }

    public function replicate(AuthUser $authUser, ScriptBlocker $scriptBlocker): bool
    {
        return $authUser->can('Replicate:ScriptBlocker');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ScriptBlocker');
    }

}