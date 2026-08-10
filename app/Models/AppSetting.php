<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'timezone',
        'date_format',
        'time_format',
    ];

    /**
     * Get the singleton settings instance (creates one if none exists).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([], [
            'timezone' => 'UTC',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
        ]);
    }

    /**
     * Get the full datetime format string (date + time combined).
     */
    public function getDatetimeFormatAttribute(): string
    {
        return $this->date_format . ', ' . $this->time_format;
    }

    /**
     * Apply this instance's timezone to the app at runtime.
     */
    public function apply(): void
    {
        config(['app.timezone' => $this->timezone]);
        config(['app.date_format' => $this->date_format]);
        config(['app.time_format' => $this->time_format]);
        date_default_timezone_set($this->timezone);
    }
}
