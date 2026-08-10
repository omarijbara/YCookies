<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainPageSet extends Model
{
    protected $fillable = [
        'domain_id',
        'set_index',
        'pages',
        'page_count',
        'last_scanned_at',
        'scan_result_id',
        'cycle_number',
    ];

    protected function casts(): array
    {
        return [
            'pages' => 'array',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function scanResult(): BelongsTo
    {
        return $this->belongsTo(ScanResult::class);
    }

    /**
     * Check if this set has been scanned in the current cycle.
     */
    public function isScanned(): bool
    {
        return $this->last_scanned_at !== null;
    }
}
