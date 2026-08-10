<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthCheckResult extends Model
{
    protected $fillable = [
        'domain_id',
        'domain_name',
        'source',
        'status',
        'checks_total',
        'checks_passed',
        'checks_warned',
        'checks_failed',
        'checks',
        'response_times',
        'headers',
        'evidence',
        'ai_diagnosis',
        'duration_ms',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'response_times' => 'array',
            'headers' => 'array',
            'evidence' => 'array',
            'ai_diagnosis' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get the overall status color for Filament badges.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'healthy' => 'success',
            'warning' => 'warning',
            'failing' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Get the status icon for display.
     */
    public function getStatusIcon(): string
    {
        return match ($this->status) {
            'healthy' => 'heroicon-o-check-circle',
            'warning' => 'heroicon-o-exclamation-triangle',
            'failing' => 'heroicon-o-x-circle',
            default => 'heroicon-o-minus-circle',
        };
    }
}
