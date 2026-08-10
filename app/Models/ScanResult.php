<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanResult extends Model
{
    protected $fillable = [
        'domain_id',
        'domain_name',
        'scanned_at',
        'source',
        'total_scripts',
        'protected_count',
        'suggested_count',
        'unknown_count',
        'unblocked_count',
        'pages_scanned_count',
        'pages_scanned',
        'scan_log',
        'scan_stages',
        'protected_scripts',
        'suggested_scripts',
        'unknown_scripts',
        'unblocked_scripts',
        'raw_scripts',
    ];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'scanned_at' => 'datetime',
            'pages_scanned' => 'array',
            'scan_log' => 'array',
            'scan_stages' => 'array',
            'protected_scripts' => 'array',
            'suggested_scripts' => 'array',
            'unknown_scripts' => 'array',
            'unblocked_scripts' => 'array',
            'raw_scripts' => 'array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
