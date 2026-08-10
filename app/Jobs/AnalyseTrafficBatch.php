<?php

namespace App\Jobs;

use App\Services\ObservabilityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Async analysis of traffic metrics for a set of domains.
 *
 * Dispatched by ObservabilityController after batch ingest.
 * Delegates to ObservabilityService for rule-engine + AI analysis.
 */
class AnalyseTrafficBatch implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * @param array<int> $domainIds
     */
    public function __construct(
        public array $domainIds,
    ) {
        $this->domainIds = array_values(array_unique(array_map('intval', $domainIds)));
        sort($this->domainIds);
        $this->onQueue('observability');
    }

    public function handle(ObservabilityService $service): void
    {
        $service->analyse($this->domainIds);
    }

    /**
     * Don't retry endlessly — analysis is best-effort.
     */
    public int $tries = 2;
    public int $backoff = 30;
    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'observability:' . implode(',', $this->domainIds);
    }

    public function uniqueFor(): int
    {
        return 60;
    }
}
