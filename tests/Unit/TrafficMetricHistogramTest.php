<?php

namespace Tests\Unit;

use App\Models\TrafficMetric;
use PHPUnit\Framework\TestCase;

/**
 * Tests for histogram-based latency aggregation.
 *
 * Verifies that histograms are mergeable across batches/instances
 * and that percentile derivation is correct.
 */
class TrafficMetricHistogramTest extends TestCase
{
    public function test_empty_histogram_has_all_bins_zeroed(): void
    {
        $h = TrafficMetric::emptyHistogram();

        $this->assertCount(8, $h);
        foreach (TrafficMetric::HISTOGRAM_BINS as $bin) {
            $this->assertSame(0, $h[$bin]);
        }
    }

    public function test_classify_latency_boundaries(): void
    {
        $this->assertSame('0_50', TrafficMetric::classifyLatency(0));
        $this->assertSame('0_50', TrafficMetric::classifyLatency(49));
        $this->assertSame('50_100', TrafficMetric::classifyLatency(50));
        $this->assertSame('100_200', TrafficMetric::classifyLatency(100));
        $this->assertSame('200_500', TrafficMetric::classifyLatency(200));
        $this->assertSame('500_1000', TrafficMetric::classifyLatency(500));
        $this->assertSame('1000_2000', TrafficMetric::classifyLatency(1000));
        $this->assertSame('2000_5000', TrafficMetric::classifyLatency(2000));
        $this->assertSame('5000_inf', TrafficMetric::classifyLatency(5000));
        $this->assertSame('5000_inf', TrafficMetric::classifyLatency(99999));
    }

    public function test_merge_histograms_sums_bins(): void
    {
        $a = TrafficMetric::emptyHistogram();
        $a['0_50'] = 10;
        $a['100_200'] = 5;

        $b = TrafficMetric::emptyHistogram();
        $b['0_50'] = 3;
        $b['500_1000'] = 7;

        $merged = TrafficMetric::mergeHistograms($a, $b);

        $this->assertSame(13, $merged['0_50']);
        $this->assertSame(5, $merged['100_200']);
        $this->assertSame(7, $merged['500_1000']);
        $this->assertSame(0, $merged['2000_5000']);
    }

    public function test_merge_is_order_independent(): void
    {
        $a = TrafficMetric::emptyHistogram();
        $a['50_100'] = 8;

        $b = TrafficMetric::emptyHistogram();
        $b['200_500'] = 4;

        $this->assertSame(
            TrafficMetric::mergeHistograms($a, $b),
            TrafficMetric::mergeHistograms($b, $a)
        );
    }

    public function test_merge_three_batches_correctly(): void
    {
        // Simulates 3 proxy instances each sending their own batch
        $batch1 = TrafficMetric::emptyHistogram();
        $batch1['0_50'] = 100;
        $batch1['50_100'] = 50;

        $batch2 = TrafficMetric::emptyHistogram();
        $batch2['0_50'] = 80;
        $batch2['100_200'] = 20;

        $batch3 = TrafficMetric::emptyHistogram();
        $batch3['0_50'] = 60;
        $batch3['500_1000'] = 10;

        $merged = TrafficMetric::mergeHistograms(
            TrafficMetric::mergeHistograms($batch1, $batch2),
            $batch3
        );

        $this->assertSame(240, $merged['0_50']);
        $this->assertSame(50, $merged['50_100']);
        $this->assertSame(20, $merged['100_200']);
        $this->assertSame(10, $merged['500_1000']);
    }

    public function test_percentile_from_empty_histogram(): void
    {
        $h = TrafficMetric::emptyHistogram();
        $this->assertSame(0, TrafficMetric::percentileFromHistogram($h, 50));
        $this->assertSame(0, TrafficMetric::percentileFromHistogram($h, 95));
    }

    public function test_percentile_from_single_bin(): void
    {
        $h = TrafficMetric::emptyHistogram();
        $h['100_200'] = 100; // all requests 100-200ms

        // p50, p95, p99 should all be in the 100-200ms bin (midpoint 150)
        $this->assertSame(150, TrafficMetric::percentileFromHistogram($h, 50));
        $this->assertSame(150, TrafficMetric::percentileFromHistogram($h, 95));
        $this->assertSame(150, TrafficMetric::percentileFromHistogram($h, 99));
    }

    public function test_percentile_p95_correctly_picks_tail_bin(): void
    {
        $h = TrafficMetric::emptyHistogram();
        $h['0_50'] = 900;      // 90% of traffic is fast
        $h['50_100'] = 50;     // 5%
        $h['1000_2000'] = 50;  // 5% — the slow tail

        // p50 should be in the 0_50 bin (midpoint 25)
        $this->assertSame(25, TrafficMetric::percentileFromHistogram($h, 50));

        // p95 should capture the 50_100 bin (midpoint 75) — 95% threshold at 950/1000
        $this->assertSame(75, TrafficMetric::percentileFromHistogram($h, 95));

        // p99 should be in the slow tail bin (midpoint 1500)
        $this->assertSame(1500, TrafficMetric::percentileFromHistogram($h, 99));
    }

    public function test_multi_batch_percentile_differs_from_incorrect_average(): void
    {
        // This is the key test: proves that merging bins then computing percentile
        // gives a different (correct) answer than averaging per-batch percentiles.

        // Batch 1: mostly fast (p95 will be ~75ms)
        $batch1 = TrafficMetric::emptyHistogram();
        $batch1['0_50'] = 90;
        $batch1['50_100'] = 10;

        // Batch 2: has a slow tail (p95 will be ~1500ms)
        $batch2 = TrafficMetric::emptyHistogram();
        $batch2['0_50'] = 85;
        $batch2['50_100'] = 5;
        $batch2['1000_2000'] = 10;

        // Incorrect approach: average the two p95s
        $wrongP95Batch1 = TrafficMetric::percentileFromHistogram($batch1, 95);
        $wrongP95Batch2 = TrafficMetric::percentileFromHistogram($batch2, 95);
        $incorrectAvgP95 = (int) (($wrongP95Batch1 + $wrongP95Batch2) / 2);

        // Correct approach: merge histograms, then compute p95
        $merged = TrafficMetric::mergeHistograms($batch1, $batch2);
        $correctP95 = TrafficMetric::percentileFromHistogram($merged, 95);

        // The two approaches give different answers — the merge is the correct one
        $this->assertNotEquals($incorrectAvgP95, $correctP95,
            'Merged histogram p95 should differ from naive average of per-batch p95s');
    }
}
