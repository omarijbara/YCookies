<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class WebhookEndpoint extends Model
{
    use HasFactory;
    protected $fillable = [
        'group_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    public const EVENT_SCAN_COMPLETED = 'scan.completed';

    public static function eventOptions(): array
    {
        return [
            self::EVENT_SCAN_COMPLETED => 'Scan completed',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function setSecretAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->attributes['secret'] = Crypt::encryptString($value);
    }

    public function getDecryptedSecretAttribute(): string
    {
        try {
            return $this->attributes['secret'] !== null && $this->attributes['secret'] !== ''
                ? Crypt::decryptString($this->attributes['secret'])
                : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function listensTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
