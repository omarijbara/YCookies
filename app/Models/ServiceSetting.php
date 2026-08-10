<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSetting extends Model
{
    protected $fillable = [
        'service_id',
        'gtm_id',
        'ga_id',
        'pixel_id',
        'opt_in_code',
        'opt_out_code',
        'fallback_code',
        'gtm_cache_locally',
    ];

    protected static function booted()
    {
        static::saved(function (ServiceSetting $setting) {
            if ($setting->gtm_cache_locally && !empty($setting->gtm_id)) {
                // Dispatch job or call service to download the script
                \App\Services\GtmDownloaderService::download($setting->gtm_id);
            }
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
