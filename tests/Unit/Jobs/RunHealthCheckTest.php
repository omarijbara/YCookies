<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunHealthCheck;
use App\Models\Domain;
use Tests\TestCase;

class RunHealthCheckTest extends TestCase
{
    public function test_it_uses_the_health_queue_with_a_larger_timeout_budget(): void
    {
        $job = new RunHealthCheck(new Domain(['name' => 'queued-health.test']), 'scheduled');

        $this->assertSame('health', $job->queue);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(2, $job->tries);
    }
}
