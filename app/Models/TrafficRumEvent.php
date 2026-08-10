<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Real User Monitoring (RUM) event — browser-side telemetry.
 *
 * Separate from TrafficMetric (edge metrics from Node proxy).
 * Same histogram bin structure for timing data.
 */
class TrafficRumEvent extends Model
{
    protected $fillable = [
        'domain_id',
        'bucket',
        'page_path',
        'pageview_count',
        'dcl_histogram',
        'load_histogram',
        'fp_histogram',
        'ttfb_histogram',
        'banner_expected_count',
        'banner_rendered_count',
        'banner_render_time_sum',
        'injection_confirmed_count',
        'injection_missing_count',
        'js_error_count',
        'js_errors',
    ];

    protected $casts = [
        'bucket'          => 'datetime',
        'dcl_histogram'   => 'array',
        'load_histogram'  => 'array',
        'fp_histogram'    => 'array',
        'ttfb_histogram'  => 'array',
        'js_errors'       => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
