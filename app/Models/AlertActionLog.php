<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertActionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'traffic_alert_state_id',
        'user_id',
        'action',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function alertState(): BelongsTo
    {
        return $this->belongsTo(TrafficAlertState::class, 'traffic_alert_state_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
