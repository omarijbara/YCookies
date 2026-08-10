<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverOutboundWebhook;
use App\Models\Domain;
use App\Models\ScanResult;
use App\Models\WebhookEndpoint;
use App\Services\OutboundWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dispatch_scan_completed_queues_delivery_for_matching_endpoint(): void
    {
        Bus::fake();

        $domain = Domain::factory()->create();
        WebhookEndpoint::factory()->create([
            'group_id' => $domain->group_id,
            'events' => [WebhookEndpoint::EVENT_SCAN_COMPLETED],
            'is_active' => true,
        ]);

        $scan = ScanResult::create([
            'domain_id' => $domain->id,
            'domain_name' => $domain->name,
            'scanned_at' => now(),
            'source' => 'test',
            'total_scripts' => 3,
            'protected_count' => 1,
            'suggested_count' => 1,
            'unknown_count' => 1,
            'pages_scanned_count' => 1,
            'pages_scanned' => ['/'],
            'scan_log' => [],
            'scan_stages' => [],
            'protected_scripts' => [],
            'suggested_scripts' => [],
            'unknown_scripts' => [],
            'raw_scripts' => [],
        ]);

        OutboundWebhookDispatcher::dispatchScanCompleted($domain, $scan);

        Bus::assertDispatched(DeliverOutboundWebhook::class, function (DeliverOutboundWebhook $job) use ($domain): bool {
            return $job->event === WebhookEndpoint::EVENT_SCAN_COMPLETED
                && ($job->payload['domain_id'] ?? null) === $domain->id;
        });
    }

    #[Test]
    public function dispatch_scan_completed_skips_when_no_subscribed_endpoints(): void
    {
        Bus::fake();

        $domain = Domain::factory()->create();
        WebhookEndpoint::factory()->create([
            'group_id' => $domain->group_id,
            'events' => [],
            'is_active' => true,
        ]);

        $scan = ScanResult::create([
            'domain_id' => $domain->id,
            'domain_name' => $domain->name,
            'scanned_at' => now(),
            'source' => 'test',
            'total_scripts' => 0,
            'protected_count' => 0,
            'suggested_count' => 0,
            'unknown_count' => 0,
            'pages_scanned_count' => 0,
            'pages_scanned' => [],
            'scan_log' => [],
            'scan_stages' => [],
            'protected_scripts' => [],
            'suggested_scripts' => [],
            'unknown_scripts' => [],
            'raw_scripts' => [],
        ]);

        OutboundWebhookDispatcher::dispatchScanCompleted($domain, $scan);

        Bus::assertNotDispatched(DeliverOutboundWebhook::class);
    }

    #[Test]
    public function deliver_outbound_webhook_posts_signed_json(): void
    {
        $this->travelTo(now()->startOfSecond());

        $endpoint = WebhookEndpoint::factory()->create([
            'url' => 'https://receiver.test/hook',
            'secret' => 'test-secret-32-chars-minimum!!',
        ]);

        Http::fake([
            'https://receiver.test/hook' => Http::response('OK', 200),
        ]);

        $job = new DeliverOutboundWebhook($endpoint->id, WebhookEndpoint::EVENT_SCAN_COMPLETED, ['domain_id' => 1]);
        $job->handle();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($endpoint): bool {
            if ($request->url() !== $endpoint->url) {
                return false;
            }
            $body = $request->body();
            $sig = $request->header('X-YCookies-Signature')[0] ?? '';
            $secret = $endpoint->fresh()->decrypted_secret;
            $calc = hash_hmac('sha256', $body, $secret);

            return hash_equals($calc, $sig)
                && ($request->header('X-YCookies-Event')[0] ?? '') === WebhookEndpoint::EVENT_SCAN_COMPLETED;
        });
    }
}
