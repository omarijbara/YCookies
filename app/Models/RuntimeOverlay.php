<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuntimeOverlay extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'revision_id',
        'overlay_id',
        'route_pattern',
        'overlay_json',
        'overlay_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RuntimeRevision::class, 'revision_id');
    }

    public function getOverlay(): array
    {
        return json_decode($this->overlay_json, true);
    }
}
