<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCookie extends Model
{
    protected $fillable = [
        'service_id',
        'name',
        'hostname',
        'lifetime',
        'purpose',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
