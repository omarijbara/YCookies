<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ConsentLog extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'domain_id',
        'consent_uid',
        'ip_hash',
        'user_agent',
        'consent_type',
        'cookie_version',
        'consents_granted',
        'services_granted',
        'tc_string',
        'provider_overrides',
        'is_latest',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'consents_granted' => 'array',
        'services_granted' => 'array',
        'provider_overrides' => 'array',
        'is_latest' => 'boolean',
        'cookie_version' => 'integer',
    ];

    /**
     * When creating a new consent log for an existing UID, mark all previous entries
     * as not latest. This mirrors Borlabs' insertAsLatestConsent() pattern.
     */
    protected static function booted()
    {
        static::creating(function (ConsentLog $log) {
            // Mark previous entries for this UID as not latest
            if ($log->consent_uid) {
                static::where('consent_uid', $log->consent_uid)
                    ->where('domain_id', $log->domain_id)
                    ->where('is_latest', true)
                    ->update(['is_latest' => false]);
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Scope for filtering by latest consent per UID only.
     */
    public function scopeLatestConsent($query)
    {
        return $query->where('is_latest', true);
    }

    /**
     * Scope for filtering by specific cookie version.
     */
    public function scopeForVersion($query, int $version)
    {
        return $query->where('cookie_version', $version);
    }

    /**
     * Get full consent history for a specific UID.
     */
    public static function getHistory(string $uid, int $domainId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('consent_uid', $uid)
            ->where('domain_id', $domainId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Clean up logs older than the given number of days.
     */
    public static function cleanUp(int $days = 365): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}
