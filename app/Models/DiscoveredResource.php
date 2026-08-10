<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveredResource extends Model
{
    protected $fillable = [
        'domain_id',
        'group_id',
        'provider_host',
        'resource_type',
        'sample_url',
        'status',
        'hit_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_to_type',
        'resolved_to_id',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (class_exists(\Filament\Facades\Filament::class)) {
                $tenant = \Filament\Facades\Filament::getTenant();
                if ($tenant) {
                    $query->where('group_id', $tenant->getKey());
                }
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * The consent key used in the visitor's consent cookie.
     * e.g. provider_host "sneaky.com" → "disc-sneaky-com"
     */
    public function consentKey(): string
    {
        return 'disc-' . str_replace('.', '-', $this->provider_host);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForDomain(Builder $query, int $domainId): Builder
    {
        return $query->where('domain_id', $domainId);
    }
}
