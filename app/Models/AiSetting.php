<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'api_key',
        'model',
        'is_active',
        'share_telemetry',
        'telemetry_token',
        'telemetry_endpoint',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'share_telemetry' => 'boolean',
    ];

    /**
     * Get the singleton settings instance (creates one if none exists).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([], [
            'provider' => 'openrouter',
            'api_key' => null,
            'model' => 'openai/gpt-4o-mini',
            'is_active' => false,
            'share_telemetry' => false,
            'telemetry_token' => null,
            'telemetry_endpoint' => 'https://improve.ypsilon.dev/api/ingest',
        ]);
    }

    /**
     * Encrypt API key on set.
     */
    public function setApiKeyAttribute($value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : '';
    }

    /**
     * Decrypt API key on get.
     */
    public function getDecryptedApiKeyAttribute(): string
    {
        try {
            return !empty($this->attributes['api_key'])
                ? Crypt::decryptString($this->attributes['api_key'])
                : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if AI is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->is_active && !empty($this->decrypted_api_key);
    }

    /**
     * Available models per provider.
     */
    public static function availableModels(): array
    {
        return [
            'openai/gpt-4o' => 'GPT-4o (Best quality, higher cost)',
            'openai/gpt-4o-mini' => 'GPT-4o Mini (Good quality, lower cost)',
            'openai/gpt-4.1-mini' => 'GPT-4.1 Mini (Latest, balanced)',
            'openai/gpt-4.1-nano' => 'GPT-4.1 Nano (Fastest, cheapest)',
            'anthropic/claude-sonnet-4' => 'Claude Sonnet 4 (Excellent reasoning)',
            'anthropic/claude-3.5-haiku' => 'Claude 3.5 Haiku (Fast & affordable)',
            'google/gemini-2.5-flash-preview' => 'Gemini 2.5 Flash (Google, fast)',
            'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash (Google, stable)',
        ];
    }
}
