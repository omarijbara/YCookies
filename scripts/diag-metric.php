<?php
// Recovery + Action Log Test — inject healthy metrics to trigger recovery

use Illuminate\Support\Facades\DB;

$domain = \App\Models\Domain::where('is_active', true)->first();
echo "Domain: {$domain->name} (ID: {$domain->id})\n\n";

// Show current alerts
$alerts = \App\Models\TrafficAlertState::where('domain_id', $domain->id)
    ->whereIn('state', ['open', 'suppressed'])->get();
echo "Active alerts: {$alerts->count()}\n";
foreach ($alerts as $a) {
    echo "  [{$a->severity}] {$a->alert_type} state={$a->state} hits={$a->hit_count}\n";
}

// Delete any old synthetic metrics
\App\Models\TrafficMetric::where('domain_id', $domain->id)
    ->where('proxy_version', 'synthetic')->delete();

// Insert HEALTHY metrics (below all thresholds)
echo "\nInserting healthy metrics...\n";
$bucket = now()->startOfMinute()->toDateTimeString();
$hm = \App\Models\TrafficMetric::create([
    'domain_id' => $domain->id, 'bucket' => $bucket,
    'route_pattern' => '/synthetic/healthy',
    'request_count' => 100, 'status_2xx' => 98, 'status_3xx' => 1,
    'status_4xx' => 1, 'status_5xx' => 0,
    'latency_histogram' => ['0_50'=>40,'50_100'=>30,'100_200'=>20,'200_500'=>8,'500_1000'=>2,'1000_2000'=>0,'2000_5000'=>0,'5000_inf'=>0],
    'ttfb_histogram' => \App\Models\TrafficMetric::emptyHistogram(),
    'cache_hits' => 80, 'cache_misses' => 20,
    'html_responses' => 95, 'inject_attempted' => 95,
    'inject_succeeded' => 95, 'inject_failed' => 0, 'passthrough_count' => 5,
    'blocked_scripts_total' => 2, 'blocked_content_total' => 0,
    'filtered_cookies_total' => 5,
    'bytes_in_total' => 200000, 'bytes_out_total' => 500000,
    'error_codes' => [], 'proxy_version' => 'synthetic', 'config_version' => 1,
]);
echo "Healthy metric created (ID: {$hm->id})\n";

// Run analysis — should trigger recovery
echo "\nRunning ObservabilityService::analyse()...\n";
$svc = new \App\Services\ObservabilityService();
$svc->analyse([$domain->id]);

// Check recovery
$resolved = \App\Models\TrafficAlertState::where('domain_id', $domain->id)
    ->where('state', 'resolved')
    ->whereIn('alert_type', ['high_latency', 'high_5xx_rate'])->get();

echo "\nResolved: {$resolved->count()}\n";
foreach ($resolved as $r) {
    echo "  {$r->alert_type} resolved at {$r->resolved_at}\n";
}

// Test action log
echo "\nTesting operator action log...\n";
$testAlert = $resolved->first();
if ($testAlert) {
    $testAlert->addNote('Synthetic E2E recovery test passed', null);
    $log = $testAlert->actionLogs()->latest('created_at')->first();
    echo "Action log: " . ($log ? "OK ({$log->action}: {$log->note})" : "FAILED") . "\n";
} else {
    echo "No resolved alert to test action log on\n";
}

// Cleanup
$hm->delete();
echo "\nCleaned up.\n";

echo "\n=== RECOVERY SUMMARY ===\n";
echo "Recovery: " . ($resolved->count() >= 1 ? 'OK' : 'FAIL') . "\n";
echo "Action log: " . (isset($log) && $log ? 'OK' : 'FAIL') . "\n";
exit(0);
