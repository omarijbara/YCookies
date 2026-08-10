<?php

namespace Tests\Unit;

use App\Services\ScriptScannerService;
use Tests\TestCase;

class PageSetTest extends TestCase
{
    public function test_calculate_set_size_small(): void
    {
        $this->assertEquals(30, ScriptScannerService::calculateSetSize(30));
        $this->assertEquals(50, ScriptScannerService::calculateSetSize(50));
    }

    public function test_calculate_set_size_medium(): void
    {
        // 2000 pages target calmer chunks of about 20 pages each.
        $this->assertEquals(20, ScriptScannerService::calculateSetSize(2000));
    }

    public function test_calculate_set_size_large(): void
    {
        // 5000 / 100 target sets = 50 pages, capped by max set size.
        $this->assertEquals(50, ScriptScannerService::calculateSetSize(5000));
    }

    public function test_calculate_set_size_very_large(): void
    {
        // Very large sites are still capped so one scheduled scan does not explode.
        $this->assertEquals(50, ScriptScannerService::calculateSetSize(10000));
    }

    public function test_calculate_set_size_minimum(): void
    {
        // 500 / 100 = 5 → min calmer set size of 15.
        $this->assertEquals(15, ScriptScannerService::calculateSetSize(500));
    }

    public function test_build_page_sets_basic(): void
    {
        $urls = [];
        for ($i = 1; $i <= 100; $i++) {
            $urls[] = "https://example.com/page-{$i}";
        }
        $priority = ['https://example.com/', 'https://example.com/about'];

        $sets = ScriptScannerService::buildPageSets($urls, 25, $priority);

        // 100 urls - 0 overlap with priority (priority URLs not in $urls) = 100 / 25 = 4 sets
        $this->assertCount(4, $sets);
        $this->assertCount(25, $sets[0]);
        $this->assertCount(25, $sets[1]);
    }

    public function test_build_page_sets_excludes_priority(): void
    {
        $urls = [
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/page-1',
            'https://example.com/page-2',
            'https://example.com/page-3',
        ];
        $priority = ['https://example.com/', 'https://example.com/about'];

        $sets = ScriptScannerService::buildPageSets($urls, 25, $priority);

        // 5 urls - 2 priority = 3 remaining → 1 set
        $this->assertCount(1, $sets);
        $this->assertCount(3, $sets[0]);
        $this->assertNotContains('https://example.com/', $sets[0]);
        $this->assertNotContains('https://example.com/about', $sets[0]);
    }

    public function test_build_page_sets_empty_when_all_priority(): void
    {
        $urls = ['https://example.com/', 'https://example.com/about'];
        $priority = ['https://example.com/', 'https://example.com/about'];

        $sets = ScriptScannerService::buildPageSets($urls, 25, $priority);

        $this->assertEmpty($sets);
    }

    public function test_detect_priority_pages(): void
    {
        $urls = [
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/contact',
            'https://example.com/blog/post-1',
            'https://example.com/blog/post-2',
            'https://example.com/products/shoes/item-1',
        ];

        $priority = ScriptScannerService::detectPriorityPages($urls, 'https://example.com');

        // Should include homepage + single-segment pages
        $this->assertContains('https://example.com', $priority);
        $this->assertContains('https://example.com/about', $priority);
        $this->assertContains('https://example.com/contact', $priority);

        // Multi-segment pages should NOT be auto-priority
        $this->assertNotContains('https://example.com/blog/post-1', $priority);
        $this->assertNotContains('https://example.com/products/shoes/item-1', $priority);
    }

    public function test_detect_priority_pages_capped_at_30(): void
    {
        $urls = ['https://example.com/'];
        for ($i = 1; $i <= 50; $i++) {
            $urls[] = "https://example.com/page-{$i}";
        }

        $priority = ScriptScannerService::detectPriorityPages($urls, 'https://example.com');

        $this->assertLessThanOrEqual(30, count($priority));
    }
}
