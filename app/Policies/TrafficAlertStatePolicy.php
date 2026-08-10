<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\TrafficAlertState;
use Illuminate\Auth\Access\HandlesAuthorization;

class TrafficAlertStatePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TrafficAlertState');
    }

    public function view(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('View:TrafficAlertState');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TrafficAlertState');
    }

    public function update(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('Update:TrafficAlertState');
    }

    public function delete(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('Delete:TrafficAlertState');
    }

    public function restore(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('Restore:TrafficAlertState');
    }

    public function forceDelete(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('ForceDelete:TrafficAlertState');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TrafficAlertState');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TrafficAlertState');
    }

    public function replicate(AuthUser $authUser, TrafficAlertState $trafficAlertState): bool
    {
        return $authUser->can('Replicate:TrafficAlertState');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TrafficAlertState');
    }

}