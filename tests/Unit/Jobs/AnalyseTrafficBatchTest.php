<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AnalyseTrafficBatch;
use Tests\TestCase;

class AnalyseTrafficBatchTest extends TestCase
{
    public function test_it_normalizes_domains_for_uniqueness_and_uses_observability_queue(): void
    {
        $job = new AnalyseTrafficBatch([9, 3, 9, 4]);

        $this->assertSame('observability', $job->queue);
        $this->assertSame([3, 4, 9], $job->domainIds);
        $this->assertSame('observability:3,4,9', $job->uniqueId());
        $this->assertSame(60, $job->uniqueFor());
        $this->assertSame(120, $job->timeout);
    }
}
