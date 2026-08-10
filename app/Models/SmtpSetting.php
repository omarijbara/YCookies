<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SmtpSetting extends Model
{
    protected $fillable = [
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
        'is_active',
        'notify_on_updates',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'notify_on_updates' => 'boolean',
        'port' => 'integer',
    ];

    /**
     * Get the singleton settings instance (creates one if none exists).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([], [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => '',
            'from_name' => 'YCookies',
            'is_active' => false,
            'notify_on_updates' => true,
        ]);
    }

    /**
     * Encrypt password on set.
     */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt password on get.
     */
    public function getDecryptedPasswordAttribute(): ?string
    {
        try {
            return $this->attributes['password'] ? Crypt::decryptString($this->attributes['password']) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the mailer config array to use with Laravel's mail system.
     */
    public function getMailerConfig(): array
    {
        return [
            'transport' => 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->decrypted_password,
            'encryption' => $this->encryption,
        ];
    }
}
