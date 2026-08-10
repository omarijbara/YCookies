<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrashReport extends Model
{
    protected $fillable = [
        'source',
        'level',
        'message',
        'stack_trace',
        'context',
        'fingerprint',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'telemetry_sent_at',
        'resolved_at',
    ];

    protected $casts = [
        'context'           => 'array',
        'first_seen_at'     => 'datetime',
        'last_seen_at'      => 'datetime',
        'telemetry_sent_at' => 'datetime',
        'resolved_at'       => 'datetime',
    ];

    /** Unsent errors ready for telemetry push. */
    public function scopeUnsent($query)
    {
        return $query->whereNull('telemetry_sent_at');
    }

    /** Resolved errors. */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /** Unresolved errors. */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
