<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrafficMetric — minute-bucketed aggregate for one domain.
 *
 * One row per domain per minute. Upserted from raw edge events
 * sent by the Node proxy in batches.
 *
 * Latency is stored as histogram bins (JSON), NOT pre-computed percentiles.
 * Percentiles (p50/p95/p99) are derived at read time via mergeHistograms().
 */
class TrafficMetric extends Model
{
    /**
     * Histogram bin boundaries in ms.
     * Each bin counts requests with latency in [lower, upper).
     */
    public const HISTOGRAM_BINS = [
        '0_50',
        '50_100',
        '100_200',
        '200_500',
        '500_1000',
        '1000_2000',
        '2000_5000',
        '5000_inf',
    ];

    /**
     * Midpoints of each bin for percentile interpolation.
     */
    public const BIN_MIDPOINTS = [
        '0_50'      => 25,
        '50_100'    => 75,
        '100_200'   => 150,
        '200_500'   => 350,
        '500_1000'  => 750,
        '1000_2000' => 1500,
        '2000_5000' => 3500,
        '5000_inf'  => 7500,
    ];

    protected $fillable = [
        'domain_id',
        'bucket',
        'route_pattern',
        'request_count',
        'status_2xx',
        'status_3xx',
        'status_4xx',
        'status_5xx',
        'latency_histogram',
        'ttfb_histogram',
        'cache_hits',
        'cache_misses',
        'html_responses',
        'inject_attempted',
        'inject_succeeded',
        'inject_failed',
        'passthrough_count',
        'blocked_scripts_total',
        'blocked_content_total',
        'filtered_cookies_total',
        'bytes_in_total',
        'bytes_out_total',
        'error_codes',
        'proxy_version',
        'config_version',
    ];

    protected function casts(): array
    {
        return [
            'bucket'            => 'datetime',
            'latency_histogram' => 'array',
            'ttfb_histogram'    => 'array',
            'error_codes'       => 'array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Create an empty histogram with all bins zeroed.
     */
    public static function emptyHistogram(): array
    {
        return array_fill_keys(self::HISTOGRAM_BINS, 0);
    }

    /**
     * Classify a latency value into the correct histogram bin.
     */
    public static function classifyLatency(int $ms): string
    {
        if ($ms < 50)   return '0_50';
        if ($ms < 100)  return '50_100';
        if ($ms < 200)  return '100_200';
        if ($ms < 500)  return '200_500';
        if ($ms < 1000) return '500_1000';
        if ($ms < 2000) return '1000_2000';
        if ($ms < 5000) return '2000_5000';
        return '5000_inf';
    }

    /**
     * Merge two histograms by summing each bin.
     * Perfectly mergeable across batches / proxy instances.
     */
    public static function mergeHistograms(array $a, array $b): array
    {
        $result = self::emptyHistogram();
        foreach (self::HISTOGRAM_BINS as $bin) {
            $result[$bin] = ($a[$bin] ?? 0) + ($b[$bin] ?? 0);
        }
        return $result;
    }

    /**
     * Derive a percentile from a histogram.
     * Uses linear interpolation within the target bin.
     *
     * @param array $histogram Bin counts
     * @param float $pct Percentile (0-100), e.g. 50, 95, 99
     * @return int Estimated latency in ms
     */
    public static function percentileFromHistogram(array $histogram, float $pct): int
    {
        $total = array_sum($histogram);
        if ($total === 0) return 0;

        $threshold = ($pct / 100) * $total;
        $cumulative = 0;

        foreach (self::HISTOGRAM_BINS as $bin) {
            $cumulative += ($histogram[$bin] ?? 0);
            if ($cumulative >= $threshold) {
                return self::BIN_MIDPOINTS[$bin];
            }
        }

        return self::BIN_MIDPOINTS['5000_inf'];
    }

    /**
     * Convenience: get p50/p95/p99 from this record's histogram.
     */
    public function getLatencyPercentiles(): array
    {
        $h = $this->latency_histogram ?? self::emptyHistogram();
        return [
            'p50' => self::percentileFromHistogram($h, 50),
            'p95' => self::percentileFromHistogram($h, 95),
            'p99' => self::percentileFromHistogram($h, 99),
        ];
    }
}
