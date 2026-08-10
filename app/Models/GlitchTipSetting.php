<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GlitchTipSetting extends Model
{
    protected $fillable = [
        'url',
        'public_url',
        'api_token',
        'org_slug',
        'projects',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'projects' => 'array',
    ];

    /**
     * Get the singleton settings instance (creates one if none exists).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([], [
            'url'        => env('GLITCHTIP_URL', 'http://glitchtip-web:8000'),
            'public_url' => env('GLITCHTIP_PUBLIC_URL', 'https://sentry.ypsilon.dev'),
            'api_token'  => null,
            'org_slug'   => env('GLITCHTIP_ORG_SLUG', 'default'),
            'is_active'  => false,
        ]);
    }

    /**
     * Encrypt API token on set.
     */
    public function setApiTokenAttribute($value): void
    {
        $this->attributes['api_token'] = $value ? Crypt::encryptString($value) : '';
    }

    /**
     * Decrypt API token on get.
     */
    public function getDecryptedApiTokenAttribute(): string
    {
        try {
            return !empty($this->attributes['api_token'])
                ? Crypt::decryptString($this->attributes['api_token'])
                : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if GlitchTip is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->is_active && !empty($this->decrypted_api_token);
    }
}
