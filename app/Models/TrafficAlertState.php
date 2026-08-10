<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * TrafficAlertState — dedup/cool-down state for traffic alerts.
 *
 * Dedup key: domain_id + alert_type.
 * State machine: open → suppressed → resolved.
 *
 * Evidence is refreshed even while suppressed so the alert always
 * reflects the latest observation when it finally re-fires or resolves.
 */
class TrafficAlertState extends Model
{
    public const STATE_OPEN       = 'open';
    public const STATE_SUPPRESSED = 'suppressed';
    public const STATE_RESOLVED   = 'resolved';

    // Cool-down windows per severity
    public const COOLDOWN_MINUTES = [
        'critical' => 10,
        'warning'  => 20,
        'info'     => 30,
    ];

    protected $fillable = [
        'domain_id',
        'alert_type',
        'state',
        'severity',
        'hit_count',
        'latest_value',
        'latest_message',
        'evidence_payload',
        'first_fired_at',
        'last_fired_at',
        'suppressed_until',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_payload' => 'array',
            'first_fired_at'   => 'datetime',
            'last_fired_at'    => 'datetime',
            'suppressed_until' => 'datetime',
            'resolved_at'      => 'datetime',
            'latest_value'     => 'float',
        ];
    }

    // ── Relationships ──────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(AlertActionLog::class)->orderByDesc('created_at');
    }

    /**
     * Tenant relationship — alert belongs to a group through domain.
     * Required by Filament's multi-tenancy for sidebar rendering.
     */
    public function group(): HasOneThrough
    {
        return $this->hasOneThrough(
            Group::class,
            Domain::class,
            'id',           // domains.id
            'id',           // groups.id
            'domain_id',    // traffic_alert_states.domain_id
            'group_id'      // domains.group_id
        );
    }

    // ── State queries ──────────────────────────────────────

    public function isSuppressed(): bool
    {
        return $this->state === self::STATE_SUPPRESSED
            && $this->suppressed_until
            && $this->suppressed_until->isFuture();
    }

    public function isResolved(): bool
    {
        return $this->state === self::STATE_RESOLVED;
    }

    public function getCooldownMinutes(): int
    {
        return self::COOLDOWN_MINUTES[$this->severity] ?? self::COOLDOWN_MINUTES['warning'];
    }

    // ── Operator actions ───────────────────────────────────

    /**
     * Acknowledge the alert — marks as seen, does not change state.
     */
    public function acknowledge(?int $userId = null, ?string $note = null): void
    {
        $this->logAction('acknowledge', $userId, $note);
    }

    /**
     * Snooze the alert — extends suppression window.
     */
    public function snooze(int $minutes, ?int $userId = null, ?string $note = null): void
    {
        $this->update([
            'state'            => self::STATE_SUPPRESSED,
            'suppressed_until' => now()->addMinutes($minutes),
        ]);

        $this->logAction('snooze', $userId, $note, [
            'snooze_minutes'   => $minutes,
            'suppressed_until' => now()->addMinutes($minutes)->toIso8601String(),
        ]);
    }

    /**
     * Manually resolve the alert.
     */
    public function manualResolve(?int $userId = null, ?string $note = null): void
    {
        $previousState = $this->state;

        $this->update([
            'state'       => self::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->logAction('resolve', $userId, $note, [
            'previous_state' => $previousState,
        ]);
    }

    /**
     * Reopen a resolved alert.
     */
    public function reopen(?int $userId = null, ?string $note = null): void
    {
        $this->update([
            'state'       => self::STATE_OPEN,
            'resolved_at' => null,
        ]);

        $this->logAction('reopen', $userId, $note);
    }

    /**
     * Add an operator note without changing state.
     */
    public function addNote(string $note, ?int $userId = null): void
    {
        $this->logAction('note', $userId, $note);
    }

    // ── Internals ──────────────────────────────────────────

    protected function logAction(string $action, ?int $userId, ?string $note, array $metadata = []): void
    {
        $this->actionLogs()->create([
            'user_id'  => $userId,
            'action'   => $action,
            'note'     => $note,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);
    }
}
