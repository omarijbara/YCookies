<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\HasTenants;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Models\Group;

use Spatie\Permission\Traits\HasRoles;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;

class User extends Authenticatable implements FilamentUser, HasTenants, HasDefaultTenant, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasPanelShield;

    /**
     * Many-to-many: a user can belong to multiple groups (tenants).
     */
    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Filament tenancy: return only groups this user is a member of.
     *
     * Previously returned Group::all() — every user saw every group.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->groups;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->groups->first();
    }

    /**
     * Filament tenancy: verify the user actually belongs to this group.
     *
     * Previously returned true unconditionally — zero tenant isolation.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->groups()->where('groups.id', $tenant->getKey())->exists();
    }

    /**
     * Retrieve the user's role for a specific tenant (Group).
     */
    public function getTenantRole(Group $group): ?string
    {
        $tenantGroup = $this->groups()->where('groups.id', $group->id)->first();
        return $tenantGroup ? $tenantGroup->pivot->role : null;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
