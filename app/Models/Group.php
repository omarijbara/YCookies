<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Cashier\Billable;
use Filament\Models\Contracts\HasName;

class Group extends Model implements HasName
{
    use Billable, HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function getFilamentName(): string
    {
        return $this->name ?? "Group {$this->id}";
    }

    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Many-to-many: users who are members of this group (tenant).
     * Used by GenerateDigestForGroup for notification delivery.
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class)->withPivot('role')->withTimestamps();
    }

    public function cookieBars(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CookieBar::class);
    }

    public function cookieGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CookieGroup::class);
    }

    public function services(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function webhookEndpoints(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function contentBlockers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContentBlocker::class);
    }

    public function scriptBlockers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ScriptBlocker::class);
    }

    public function getDomainLimitAttribute(): int
    {
        if (!$this->subscribed('default')) {
            return config('pricing.domain_limits.free', 1);
        }

        $price = $this->subscription('default')?->stripe_price;

        return match ($price) {
            config('pricing.pro_monthly') => config('pricing.domain_limits.pro', 10),
            config('pricing.agency_monthly') => config('pricing.domain_limits.agency', 9999),
            config('pricing.enterprise') => config('pricing.domain_limits.enterprise', 99999),
            default => config('pricing.domain_limits.free', 1),
        };
    }

    public function getScanLimitAttribute(): int
    {
        if (!$this->subscribed('default')) {
            return config('pricing.scan_limits.free', 5);
        }

        $price = $this->subscription('default')?->stripe_price;

        return match ($price) {
            config('pricing.pro_monthly') => config('pricing.scan_limits.pro', 100),
            config('pricing.agency_monthly') => config('pricing.scan_limits.agency', 99999),
            config('pricing.enterprise') => config('pricing.scan_limits.enterprise', 999999),
            default => config('pricing.scan_limits.free', 5),
        };
    }

    public function getScansThisMonthAttribute(): int
    {
        return \App\Models\ScanResult::whereHas('domain', function ($query) {
            $query->where('group_id', $this->id);
        })->whereMonth('scanned_at', now()->month)
          ->whereYear('scanned_at', now()->year)
          ->count();
    }

    public function canCreateDomain(): bool
    {
        return $this->domains()->count() < $this->domain_limit;
    }

    public function canRunScan(): bool
    {
        return $this->scans_this_month < $this->scan_limit;
    }
}
