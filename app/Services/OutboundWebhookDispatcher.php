<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverOutboundWebhook;
use App\Models\Domain;
use App\Models\ScanResult;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;

final class OutboundWebhookDispatcher
{
    public static function dispatchForGroup(int $groupId, string $event, array $payload): void
    {
        WebhookEndpoint::query()
            ->where('group_id', $groupId)
            ->where('is_active', true)
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint) => $endpoint->listensTo($event))
            ->each(function (WebhookEndpoint $endpoint) use ($event, $payload): void {
                DeliverOutboundWebhook::dispatch($endpoint->id, $event, $payload);
            });
    }

    public static function dispatchScanCompleted(Domain $domain, ScanResult $scanResult): void
    {
        if (! $domain->group_id) {
            return;
        }

        try {
            self::dispatchForGroup((int) $domain->group_id, WebhookEndpoint::EVENT_SCAN_COMPLETED, [
                'domain_id' => $domain->id,
                'site_id' => $domain->site_id,
                'scan_result_id' => $scanResult->id,
                'status' => 'success',
                'data' => [
                    'total_scripts' => $scanResult->total_scripts,
                    'protected_count' => $scanResult->protected_count,
                    'suggested_count' => $scanResult->suggested_count,
                    'unknown_count' => $scanResult->unknown_count,
                    'pages_scanned_count' => $scanResult->pages_scanned_count,
                    'source' => $scanResult->source,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('outbound_webhook.scan_completed_dispatch_failed', [
                'domain_id' => $domain->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
