<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyseTrafficBatch;
use App\Models\Domain;
use App\Models\TrafficMetric;
use App\Models\TrafficRumEvent;
use App\Support\RouteFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receives raw edge metric batches from the Node proxy,
 * aggregates them into minute-bucketed rows, and queues analysis.
 *
 * Also receives browser RUM beacons from end users.
 *
 * HMAC-verified via proxy.hmac middleware (edge metrics only).
 */
class ObservabilityController extends Controller
{
    /**
     * Ingest a batch of raw edge metrics from the Node proxy.
     */
    public function ingest(Request $request): JsonResponse
    {
        $batch = $request->json()->all();

        // Sanity: cap batch size
        if (count($batch) > 1000) {
            return response()->json(['error' => 'batch too large'], 413);
        }

        if (empty($batch)) {
            return response()->json(['status' => 'empty']);
        }

        // ── Batch-level idempotency ────────────────────────
        $batchId = $request->header('X-Batch-Id');
        if ($batchId) {
            $alreadyProcessed = DB::table('processed_metric_batches')
                ->where('batch_id', $batchId)
                ->exists();

            if ($alreadyProcessed) {
                return response()->json([
                    'status'  => 'duplicate',
                    'message' => 'Batch already processed',
                ]);
            }
        }

        // Resolve domain_id for all hostnames in one query
        $hosts = collect($batch)->pluck('domain')->unique()->filter()->values();
        $domainMap = Domain::whereIn('name', $hosts)->pluck('id', 'name');

        // Group events by (domain_id, minute bucket)
        $buckets = [];
        foreach ($batch as $event) {
            $domainId = $domainMap[$event['domain'] ?? ''] ?? null;
            $minute = Carbon::createFromTimestampMs($event['ts'] ?? now()->getTimestampMs())
                ->startOfMinute()
                ->toDateTimeString();

            // Use pre-fingerprinted route_pattern from Node, or normalize raw path as safety net
            $routePattern = $event['route_pattern']
                ?? RouteFingerprint::normalize($event['path'] ?? '/');

            $key = ($domainId ?? 'null') . '|' . $minute . '|' . $routePattern;

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'domain_id'     => $domainId,
                    'bucket'        => $minute,
                    'route_pattern' => $routePattern,
                    'request_count' => 0,
                    'status_2xx'    => 0,
                    'status_3xx'    => 0,
                    'status_4xx'    => 0,
                    'status_5xx'    => 0,
                    'latency_histogram' => TrafficMetric::emptyHistogram(),
                    'ttfb_histogram'    => TrafficMetric::emptyHistogram(),
                    'cache_hits'    => 0,
                    'cache_misses'  => 0,
                    'html_responses'    => 0,
                    'inject_attempted'  => 0,
                    'inject_succeeded'  => 0,
                    'inject_failed'     => 0,
                    'passthrough_count' => 0,
                    'blocked_scripts_total'  => 0,
                    'blocked_content_total'  => 0,
                    'filtered_cookies_total' => 0,
                    'bytes_in_total'  => 0,
                    'bytes_out_total' => 0,
                    'error_codes'    => [],
                    'proxy_version'  => null,
                    'config_version' => null,
                ];
            }

            $b = &$buckets[$key];
            $b['request_count']++;

            // ── Status class split ─────────────────────────
            $status = (int) ($event['status'] ?? 0);
            if ($status >= 200 && $status < 300)      $b['status_2xx']++;
            elseif ($status >= 300 && $status < 400)   $b['status_3xx']++;
            elseif ($status >= 400 && $status < 500)   $b['status_4xx']++;
            elseif ($status >= 500)                     $b['status_5xx']++;

            // ── Latency histogram (mergeable!) ─────────────
            $durationMs = (int) ($event['duration_ms'] ?? 0);
            $latencyBin = TrafficMetric::classifyLatency($durationMs);
            $b['latency_histogram'][$latencyBin]++;

            if (($event['origin_ttfb_ms'] ?? null) !== null) {
                $ttfbBin = TrafficMetric::classifyLatency((int) $event['origin_ttfb_ms']);
                $b['ttfb_histogram'][$ttfbBin]++;
            }

            // ── Cache ──────────────────────────────────────
            if (($event['from_cache'] ?? '') === 'hit') {
                $b['cache_hits']++;
            } else {
                $b['cache_misses']++;
            }

            // ── Injection (detailed tracking) ──────────────
            $responseType = $event['response_type'] ?? '';
            if ($responseType === 'html') {
                $b['html_responses']++;
                if ($event['html_injected'] === true) {
                    $b['inject_attempted']++;
                    $b['inject_succeeded']++;
                } elseif ($event['html_injected'] === false) {
                    $b['inject_attempted']++;
                    $b['inject_failed']++;
                }
                // html_injected=null means injection not applicable (no script URL configured)
            } elseif ($responseType === 'passthrough') {
                $b['passthrough_count']++;
            }

            // ── Blocking & filtering ───────────────────────
            $b['blocked_scripts_total'] += (int) ($event['blocked_scripts'] ?? 0);
            $b['blocked_content_total'] += (int) ($event['blocked_content'] ?? 0);
            $b['filtered_cookies_total'] += (int) ($event['filtered_cookies'] ?? 0);

            // ── Bytes ──────────────────────────────────────
            $b['bytes_in_total'] += (int) ($event['bytes_in'] ?? 0);
            $b['bytes_out_total'] += (int) ($event['bytes_out'] ?? 0);

            // ── Error codes ────────────────────────────────
            $errorCode = $event['error_code'] ?? null;
            if ($errorCode) {
                $b['error_codes'][$errorCode] = ($b['error_codes'][$errorCode] ?? 0) + 1;
            }

            // ── Version tracking (take last seen) ──────────
            $b['proxy_version'] = $event['proxy_version'] ?? $b['proxy_version'];
            $b['config_version'] = $event['config_version'] ?? $b['config_version'];
        }

        // ── Upsert into traffic_metrics ────────────────────
        $affectedDomains = [];
        DB::transaction(function () use ($buckets, &$affectedDomains) {
            foreach ($buckets as $b) {
                if ($b['domain_id'] === null) {
                    continue;
                }

                $existing = TrafficMetric::where('domain_id', $b['domain_id'])
                    ->where('bucket', $b['bucket'])
                    ->where('route_pattern', $b['route_pattern'])
                    ->first();

                if ($existing) {
                    // Merge histograms — this is clean and correct across batches
                    $mergedLatency = TrafficMetric::mergeHistograms(
                        $existing->latency_histogram ?? TrafficMetric::emptyHistogram(),
                        $b['latency_histogram']
                    );
                    $mergedTtfb = TrafficMetric::mergeHistograms(
                        $existing->ttfb_histogram ?? TrafficMetric::emptyHistogram(),
                        $b['ttfb_histogram']
                    );

                    // Merge error codes
                    $existingErrors = $existing->error_codes ?? [];
                    foreach ($b['error_codes'] as $code => $count) {
                        $existingErrors[$code] = ($existingErrors[$code] ?? 0) + $count;
                    }

                    $existing->update([
                        'request_count'          => $existing->request_count + $b['request_count'],
                        'status_2xx'             => $existing->status_2xx + $b['status_2xx'],
                        'status_3xx'             => $existing->status_3xx + $b['status_3xx'],
                        'status_4xx'             => $existing->status_4xx + $b['status_4xx'],
                        'status_5xx'             => $existing->status_5xx + $b['status_5xx'],
                        'latency_histogram'      => $mergedLatency,
                        'ttfb_histogram'         => $mergedTtfb,
                        'cache_hits'             => $existing->cache_hits + $b['cache_hits'],
                        'cache_misses'           => $existing->cache_misses + $b['cache_misses'],
                        'html_responses'         => $existing->html_responses + $b['html_responses'],
                        'inject_attempted'       => $existing->inject_attempted + $b['inject_attempted'],
                        'inject_succeeded'       => $existing->inject_succeeded + $b['inject_succeeded'],
                        'inject_failed'          => $existing->inject_failed + $b['inject_failed'],
                        'passthrough_count'      => $existing->passthrough_count + $b['passthrough_count'],
                        'blocked_scripts_total'  => $existing->blocked_scripts_total + $b['blocked_scripts_total'],
                        'blocked_content_total'  => $existing->blocked_content_total + $b['blocked_content_total'],
                        'filtered_cookies_total' => $existing->filtered_cookies_total + $b['filtered_cookies_total'],
                        'bytes_in_total'         => $existing->bytes_in_total + $b['bytes_in_total'],
                        'bytes_out_total'        => $existing->bytes_out_total + $b['bytes_out_total'],
                        'error_codes'            => !empty($existingErrors) ? $existingErrors : null,
                        'proxy_version'          => $b['proxy_version'] ?? $existing->proxy_version,
                        'config_version'         => $b['config_version'] ?? $existing->config_version,
                    ]);
                } else {
                    TrafficMetric::create([
                        'domain_id'              => $b['domain_id'],
                        'bucket'                 => $b['bucket'],
                        'route_pattern'          => $b['route_pattern'],
                        'request_count'          => $b['request_count'],
                        'status_2xx'             => $b['status_2xx'],
                        'status_3xx'             => $b['status_3xx'],
                        'status_4xx'             => $b['status_4xx'],
                        'status_5xx'             => $b['status_5xx'],
                        'latency_histogram'      => $b['latency_histogram'],
                        'ttfb_histogram'         => $b['ttfb_histogram'],
                        'cache_hits'             => $b['cache_hits'],
                        'cache_misses'           => $b['cache_misses'],
                        'html_responses'         => $b['html_responses'],
                        'inject_attempted'       => $b['inject_attempted'],
                        'inject_succeeded'       => $b['inject_succeeded'],
                        'inject_failed'          => $b['inject_failed'],
                        'passthrough_count'      => $b['passthrough_count'],
                        'blocked_scripts_total'  => $b['blocked_scripts_total'],
                        'blocked_content_total'  => $b['blocked_content_total'],
                        'filtered_cookies_total' => $b['filtered_cookies_total'],
                        'bytes_in_total'         => $b['bytes_in_total'],
                        'bytes_out_total'        => $b['bytes_out_total'],
                        'error_codes'            => !empty($b['error_codes']) ? $b['error_codes'] : null,
                        'proxy_version'          => $b['proxy_version'],
                        'config_version'         => $b['config_version'],
                    ]);
                }

                $affectedDomains[$b['domain_id']] = true;
            }
        });

        // ── Record batch as processed (idempotency) ────────
        if ($batchId) {
            DB::table('processed_metric_batches')->insert([
                'batch_id'     => $batchId,
                'processed_at' => now(),
            ]);
        }

        // ── Clean old batch records (TTL: 1 hour) ──────────
        DB::table('processed_metric_batches')
            ->where('processed_at', '<', now()->subHour())
            ->delete();

        // Queue async analysis for affected domains
        if (!empty($affectedDomains)) {
            AnalyseTrafficBatch::dispatch(array_keys($affectedDomains));
        }

        return response()->json([
            'status'   => 'ok',
            'ingested' => count($batch),
            'buckets'  => count($buckets),
        ]);
    }

    /**
     * Ingest a single RUM beacon from a browser.
     *
     * Browser-originated (no HMAC), rate-limited via throttle middleware.
     * Upserts into traffic_rum_events with histogram merging.
     */
    public function ingestRum(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        // Validate required fields
        $domain = $data['domain'] ?? null;
        $path   = $data['path'] ?? '/';
        $ts     = $data['ts'] ?? null;

        if (!$domain || !$ts) {
            return response()->json(['error' => 'missing domain or ts'], 422);
        }

        // Resolve domain
        $domainModel = Domain::where('name', $domain)->first();
        if (!$domainModel) {
            return response()->json(['status' => 'unknown_domain'], 200);
        }

        // Fingerprint the path (same as edge metrics)
        $pagePath = RouteFingerprint::normalize($path);
        $bucket   = Carbon::createFromTimestampMs($ts)->startOfMinute()->toDateTimeString();

        // Classify browser timings into histogram bins — cap at 60s to prevent overflow data
        $dclBin  = TrafficMetric::classifyLatency(min(60000, max(0, (int) ($data['dcl'] ?? 0))));
        $loadBin = TrafficMetric::classifyLatency(min(60000, max(0, (int) ($data['load'] ?? 0))));
        $fpBin   = TrafficMetric::classifyLatency(min(60000, max(0, (int) ($data['fp'] ?? 0))));
        $ttfbBin = TrafficMetric::classifyLatency(min(60000, max(0, (int) ($data['ttfb'] ?? 0))));

        $bannerExpected     = (int) ($data['banner_expected'] ?? 0);
        $bannerRendered     = (int) ($data['banner_rendered'] ?? 0);
        $bannerRenderMs     = max(0, (int) ($data['banner_render_ms'] ?? 0));
        $injectConfirmed    = (int) ($data['inject_confirmed'] ?? 0);
        $injectMissing      = (int) ($data['inject_missing'] ?? 0);

        // JS errors — cap at 20 entries
        $jsErrors = [];
        $jsErrorCount = 0;
        if (!empty($data['js_errors']) && is_array($data['js_errors'])) {
            foreach (array_slice($data['js_errors'], 0, 10) as $msg) {
                $key = substr((string) $msg, 0, 200);
                $jsErrors[$key] = ($jsErrors[$key] ?? 0) + 1;
                $jsErrorCount++;
            }
        }

        // Upsert into traffic_rum_events
        $existing = TrafficRumEvent::where('domain_id', $domainModel->id)
            ->where('bucket', $bucket)
            ->where('page_path', $pagePath)
            ->first();

        if ($existing) {
            // Merge histograms
            $mergeDcl  = TrafficMetric::mergeHistograms($existing->dcl_histogram ?? TrafficMetric::emptyHistogram(), [$dclBin => 1] + TrafficMetric::emptyHistogram());
            $mergeLoad = TrafficMetric::mergeHistograms($existing->load_histogram ?? TrafficMetric::emptyHistogram(), [$loadBin => 1] + TrafficMetric::emptyHistogram());
            $mergeFp   = TrafficMetric::mergeHistograms($existing->fp_histogram ?? TrafficMetric::emptyHistogram(), [$fpBin => 1] + TrafficMetric::emptyHistogram());
            $mergeTtfb = TrafficMetric::mergeHistograms($existing->ttfb_histogram ?? TrafficMetric::emptyHistogram(), [$ttfbBin => 1] + TrafficMetric::emptyHistogram());

            // Merge JS errors (cap at 20 distinct keys)
            $existingJsErrors = $existing->js_errors ?? [];
            foreach ($jsErrors as $msg => $count) {
                $existingJsErrors[$msg] = ($existingJsErrors[$msg] ?? 0) + $count;
            }
            if (count($existingJsErrors) > 20) {
                $existingJsErrors = array_slice($existingJsErrors, 0, 20, true);
            }

            $existing->update([
                'pageview_count'          => $existing->pageview_count + 1,
                'dcl_histogram'           => $mergeDcl,
                'load_histogram'          => $mergeLoad,
                'fp_histogram'            => $mergeFp,
                'ttfb_histogram'          => $mergeTtfb,
                'banner_expected_count'   => $existing->banner_expected_count + $bannerExpected,
                'banner_rendered_count'   => $existing->banner_rendered_count + $bannerRendered,
                'banner_render_time_sum'  => $existing->banner_render_time_sum + ($bannerRendered ? $bannerRenderMs : 0),
                'injection_confirmed_count' => $existing->injection_confirmed_count + $injectConfirmed,
                'injection_missing_count'   => $existing->injection_missing_count + $injectMissing,
                'js_error_count'          => $existing->js_error_count + $jsErrorCount,
                'js_errors'               => !empty($existingJsErrors) ? $existingJsErrors : null,
            ]);
        } else {
            // Build single-event histograms
            $singleDcl  = TrafficMetric::emptyHistogram(); $singleDcl[$dclBin]++;
            $singleLoad = TrafficMetric::emptyHistogram(); $singleLoad[$loadBin]++;
            $singleFp   = TrafficMetric::emptyHistogram(); $singleFp[$fpBin]++;
            $singleTtfb = TrafficMetric::emptyHistogram(); $singleTtfb[$ttfbBin]++;

            TrafficRumEvent::create([
                'domain_id'               => $domainModel->id,
                'bucket'                  => $bucket,
                'page_path'               => $pagePath,
                'pageview_count'          => 1,
                'dcl_histogram'           => $singleDcl,
                'load_histogram'          => $singleLoad,
                'fp_histogram'            => $singleFp,
                'ttfb_histogram'          => $singleTtfb,
                'banner_expected_count'   => $bannerExpected,
                'banner_rendered_count'   => $bannerRendered,
                'banner_render_time_sum'  => $bannerRendered ? $bannerRenderMs : 0,
                'injection_confirmed_count' => $injectConfirmed,
                'injection_missing_count'   => $injectMissing,
                'js_error_count'          => $jsErrorCount,
                'js_errors'               => !empty($jsErrors) ? $jsErrors : null,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
