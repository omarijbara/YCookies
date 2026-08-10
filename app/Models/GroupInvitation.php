<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupInvitation extends Model
{
    protected $fillable = [
        'group_id',
        'email',
        'role',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    protected static function booted()
    {
        static::creating(function ($invitation) {
            $invitation->token = \Illuminate\Support\Str::random(32);
            $invitation->expires_at = now()->addDays(7);
        });
    }
}
