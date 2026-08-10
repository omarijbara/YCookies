<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ContentBlocker;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContentBlockerPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ContentBlocker');
    }

    public function view(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('View:ContentBlocker');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ContentBlocker');
    }

    public function update(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('Update:ContentBlocker');
    }

    public function delete(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('Delete:ContentBlocker');
    }

    public function restore(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('Restore:ContentBlocker');
    }

    public function forceDelete(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('ForceDelete:ContentBlocker');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ContentBlocker');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ContentBlocker');
    }

    public function replicate(AuthUser $authUser, ContentBlocker $contentBlocker): bool
    {
        return $authUser->can('Replicate:ContentBlocker');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ContentBlocker');
    }

}