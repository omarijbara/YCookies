<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CookieConsentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'ip_address',
        'user_agent',
        'region',
        'consent_data',
    ];

    protected $casts = [
        'consent_data' => 'array',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
