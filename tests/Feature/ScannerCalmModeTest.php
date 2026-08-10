<?php

namespace Tests\Feature;

use App\Jobs\ScanDomainCookies;
use App\Models\Domain;
use App\Models\DomainPageSet;
use App\Services\ScriptScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScannerCalmModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebalance_page_set_splits_large_sets_into_calmer_chunks(): void
    {
        $domain = Domain::factory()->create();

        $pages = [];
        for ($i = 1; $i <= 27; $i++) {
            $pages[] = "https://{$domain->name}/page-{$i}";
        }

        $set = DomainPageSet::create([
            'domain_id' => $domain->id,
            'set_index' => 0,
            'pages' => $pages,
            'page_count' => count($pages),
            'cycle_number' => 1,
        ]);

        DomainPageSet::create([
            'domain_id' => $domain->id,
            'set_index' => 1,
            'pages' => ["https://{$domain->name}/later"],
            'page_count' => 1,
            'cycle_number' => 1,
        ]);

        $addedChunks = ScriptScannerService::rebalancePageSet($set, 10);

        $this->assertSame(2, $addedChunks);

        $sets = DomainPageSet::where('domain_id', $domain->id)
            ->where('cycle_number', 1)
            ->orderBy('set_index')
            ->get()
            ->values();

        $this->assertCount(4, $sets);
        $this->assertSame(10, $sets[0]->page_count);
        $this->assertSame(array_slice($pages, 0, 10), $sets[0]->pages);
        $this->assertSame(10, $sets[1]->page_count);
        $this->assertSame(array_slice($pages, 10, 10), $sets[1]->pages);
        $this->assertSame(7, $sets[2]->page_count);
        $this->assertSame(array_slice($pages, 20, 7), $sets[2]->pages);
        $this->assertSame(3, $sets[3]->set_index);
        $this->assertSame(["https://{$domain->name}/later"], $sets[3]->pages);
    }

    public function test_rebalance_page_set_leaves_small_sets_unchanged(): void
    {
        $domain = Domain::factory()->create();

        $set = DomainPageSet::create([
            'domain_id' => $domain->id,
            'set_index' => 0,
            'pages' => ["https://{$domain->name}/page-1", "https://{$domain->name}/page-2"],
            'page_count' => 2,
            'cycle_number' => 1,
        ]);

        $addedChunks = ScriptScannerService::rebalancePageSet($set, 10);

        $this->assertSame(0, $addedChunks);
        $this->assertDatabaseCount('domain_page_sets', 1);
        $this->assertSame(2, $set->fresh()->page_count);
    }

    public function test_scan_job_targets_scanner_queue_without_redeclaring_trait_property(): void
    {
        $domain = Domain::factory()->create();

        $job = new ScanDomainCookies($domain);

        $this->assertSame('scanner', $job->queue);
    }
}
