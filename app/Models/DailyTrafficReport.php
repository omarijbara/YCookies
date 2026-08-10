<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * DailyTrafficReport — one row per domain per day, plus a group summary row.
 *
 * Group summary rows use domain_id = NULL and contain aggregated KPIs
 * across all domains in the group. These are the notification source of truth.
 *
 * Schema design principles:
 * - kpi_blob + trend_json = future-proof, cheap to render later
 * - summary_status + recommendations_json = deterministic, no AI required
 * - ai_brief = additive layer, never required for core functionality
 * - notified_at = idempotency guard for notification delivery
 */
class DailyTrafficReport extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    const STATUS_STABLE   = 'stable';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_CRITICAL = 'critical';
    const STATUS_NO_DATA  = 'no_data';

    protected $fillable = [
        'group_id',
        'domain_id',
        'report_date',
        'total_requests',
        'edge_p95_latency_ms',
        'inject_rate',
        'banner_render_rate',
        'alert_count',
        'kpi_blob',
        'summary_status',
        'trend_json',
        'recommendations_json',
        'ai_brief',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date'          => 'date',
            'kpi_blob'             => 'array',
            'trend_json'           => 'array',
            'recommendations_json' => 'array',
            'notified_at'          => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    // ── Scopes ──────────────────────────────────────────────

    /**
     * Scope to group summary rows (domain_id IS NULL).
     * These are the notification source of truth.
     */
    public function scopeGroupSummary(Builder $query): Builder
    {
        return $query->whereNull('domain_id');
    }

    /**
     * Scope to domain-level rows (domain_id IS NOT NULL).
     */
    public function scopeDomainLevel(Builder $query): Builder
    {
        return $query->whereNotNull('domain_id');
    }

    /**
     * Scope to a specific group.
     */
    public function scopeForGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * Scope to a specific date.
     */
    public function scopeForDate(Builder $query, $date): Builder
    {
        return $query->whereDate('report_date', $date);
    }

    // ── Accessors ───────────────────────────────────────────

    /**
     * Whether this is a group summary row (domain_id = null).
     */
    public function getIsGroupSummaryAttribute(): bool
    {
        return $this->domain_id === null;
    }

    /**
     * Whether notification has been sent for this report.
     */
    public function getIsNotifiedAttribute(): bool
    {
        return $this->notified_at !== null;
    }
}
