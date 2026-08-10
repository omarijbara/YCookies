<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverOutboundWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public function __construct(
        public int $webhookEndpointId,
        public string $event,
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::query()->find($this->webhookEndpointId);
        if (! $endpoint || ! $endpoint->is_active || ! $endpoint->listensTo($this->event)) {
            return;
        }

        $secret = $endpoint->decrypted_secret;
        if ($secret === '') {
            Log::warning('outbound_webhook.missing_secret', ['webhook_endpoint_id' => $endpoint->id]);

            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'timestamp' => now()->toIso8601String(),
            ...$this->payload,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $body, $secret);

        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-YCookies-Event' => $this->event,
                'X-YCookies-Signature' => $signature,
                'User-Agent' => 'YCookies-Webhook/1.0',
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);

        if (! $response->successful()) {
            Log::warning('outbound_webhook.delivery_failed', [
                'webhook_endpoint_id' => $endpoint->id,
                'url' => $endpoint->url,
                'status' => $response->status(),
                'body' => \Illuminate\Support\Str::limit($response->body(), 500),
            ]);

            try {
                $response->throw();
            } catch (\Throwable $e) {
                \App\Services\CrashReporter::report($e, [
                    'source'              => 'webhook-delivery',
                    'url'                 => '/admin/settings',
                    'webhook_endpoint_id' => $endpoint->id,
                    'webhook_url'         => $endpoint->url,
                ]);
                throw $e;
            }
        }
    }
}
