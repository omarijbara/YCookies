<?php

namespace Tests\Unit;

use App\Services\ScriptScannerService;
use Tests\TestCase;

class SmartSampleTest extends TestCase
{
    /**
     * Test extractPathPattern with various URL structures.
     */
    public function test_extract_path_pattern_root(): void
    {
        $this->assertEquals('/', ScriptScannerService::extractPathPattern('https://example.com/'));
        $this->assertEquals('/', ScriptScannerService::extractPathPattern('https://example.com'));
    }

    public function test_extract_path_pattern_single_segment(): void
    {
        $this->assertEquals('/about', ScriptScannerService::extractPathPattern('https://example.com/about'));
        $this->assertEquals('/contact', ScriptScannerService::extractPathPattern('https://example.com/contact'));
    }

    public function test_extract_path_pattern_two_segments(): void
    {
        $this->assertEquals('/blog/*', ScriptScannerService::extractPathPattern('https://example.com/blog/my-post'));
        $this->assertEquals('/products/*', ScriptScannerService::extractPathPattern('https://example.com/products/shoes'));
    }

    public function test_extract_path_pattern_three_segments(): void
    {
        // /products/shoes/nike-air → /products/shoes/*
        $this->assertEquals('/products/shoes/*', ScriptScannerService::extractPathPattern('https://example.com/products/shoes/nike-air'));
    }

    /**
     * Test smartSample with small input (should return unchanged).
     */
    public function test_smart_sample_small_input_returns_all(): void
    {
        $urls = [
            'https://example.com',
            'https://example.com/about',
            'https://example.com/contact',
        ];

        $result = ScriptScannerService::smartSample($urls, 'https://example.com');

        $this->assertCount(3, $result);
        $this->assertEquals($urls, $result);
    }

    /**
     * Test smartSample with diverse URL patterns picks from each group.
     */
    public function test_smart_sample_diverse_patterns(): void
    {
        $urls = ['https://example.com'];

        // Generate 200 blog posts
        for ($i = 1; $i <= 200; $i++) {
            $urls[] = "https://example.com/blog/post-{$i}";
        }
        // Generate 100 product pages
        for ($i = 1; $i <= 100; $i++) {
            $urls[] = "https://example.com/products/item-{$i}";
        }
        // Generate 50 category pages
        for ($i = 1; $i <= 50; $i++) {
            $urls[] = "https://example.com/category/cat-{$i}";
        }
        // Add some single-segment pages
        $urls[] = 'https://example.com/about';
        $urls[] = 'https://example.com/contact';
        $urls[] = 'https://example.com/impressum';

        $result = ScriptScannerService::smartSample($urls, 'https://example.com');

        // Should be capped at MAX_PAGES (50)
        $this->assertLessThanOrEqual(50, count($result));

        // Should always include homepage
        $this->assertContains('https://example.com', $result);

        // Should include pages from each URL group
        $hasBlog = false;
        $hasProducts = false;
        $hasCategory = false;
        $hasSingleSegment = false;

        foreach ($result as $url) {
            if (str_contains($url, '/blog/')) $hasBlog = true;
            if (str_contains($url, '/products/')) $hasProducts = true;
            if (str_contains($url, '/category/')) $hasCategory = true;
            if (in_array($url, ['https://example.com/about', 'https://example.com/contact', 'https://example.com/impressum'])) {
                $hasSingleSegment = true;
            }
        }

        $this->assertTrue($hasBlog, 'Should include blog pages');
        $this->assertTrue($hasProducts, 'Should include product pages');
        $this->assertTrue($hasCategory, 'Should include category pages');
        $this->assertTrue($hasSingleSegment, 'Should include single-segment pages');
    }

    /**
     * Test smartSample with all same pattern still returns diverse sample.
     */
    public function test_smart_sample_all_same_pattern(): void
    {
        $urls = ['https://example.com'];

        // 200 blog posts, all same pattern /blog/*
        for ($i = 1; $i <= 200; $i++) {
            $urls[] = "https://example.com/blog/post-{$i}";
        }

        $result = ScriptScannerService::smartSample($urls, 'https://example.com');

        // Should be capped at MAX_PAGES
        $this->assertLessThanOrEqual(50, count($result));

        // Should always include homepage
        $this->assertContains('https://example.com', $result);

        // Should include some blog posts
        $blogCount = count(array_filter($result, fn($u) => str_contains($u, '/blog/')));
        $this->assertGreaterThan(0, $blogCount);
    }

    /**
     * Test that result never exceeds MAX_PAGES even with many groups.
     */
    public function test_smart_sample_respects_max_pages(): void
    {
        $urls = ['https://example.com'];

        // Create 100 different pattern groups with 10 pages each
        for ($g = 1; $g <= 100; $g++) {
            for ($p = 1; $p <= 10; $p++) {
                $urls[] = "https://example.com/section-{$g}/page-{$p}";
            }
        }

        $result = ScriptScannerService::smartSample($urls, 'https://example.com');

        $this->assertLessThanOrEqual(50, count($result));
        $this->assertContains('https://example.com', $result);
    }

    /**
     * Test that no duplicate URLs appear in the result.
     */
    public function test_smart_sample_no_duplicates(): void
    {
        $urls = ['https://example.com'];
        for ($i = 1; $i <= 200; $i++) {
            $urls[] = "https://example.com/page/post-{$i}";
        }

        $result = ScriptScannerService::smartSample($urls, 'https://example.com');

        $this->assertEquals(count($result), count(array_unique($result)), 'Result should contain no duplicates');
    }
}
