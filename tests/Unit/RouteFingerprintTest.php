<?php

namespace Tests\Unit;

use App\Support\RouteFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Tests for route fingerprinting — verifies correct normalization
 * of dynamic URL segments while preserving meaningful structure.
 */
class RouteFingerprintTest extends TestCase
{
    // ── Basic normalization ────────────────────────────────

    public function test_root_path(): void
    {
        $this->assertSame('/', RouteFingerprint::normalize('/'));
        $this->assertSame('/', RouteFingerprint::normalize(''));
        $this->assertSame('/', RouteFingerprint::normalize(null));
    }

    public function test_static_paths_preserved(): void
    {
        $this->assertSame('/about', RouteFingerprint::normalize('/about'));
        $this->assertSame('/contact/form', RouteFingerprint::normalize('/contact/form'));
        $this->assertSame('/api/health', RouteFingerprint::normalize('/api/health'));
    }

    // ── Numeric IDs → :id ──────────────────────────────────

    public function test_numeric_ids_replaced(): void
    {
        $this->assertSame('/checkout/:id', RouteFingerprint::normalize('/checkout/12345'));
        $this->assertSame('/users/:id/posts', RouteFingerprint::normalize('/users/42/posts'));
        $this->assertSame('/api/orders/:id/items/:id', RouteFingerprint::normalize('/api/orders/99/items/7'));
    }

    // ── UUIDs → :uuid ──────────────────────────────────────

    public function test_uuids_replaced(): void
    {
        $this->assertSame('/users/:uuid', RouteFingerprint::normalize('/users/9f1c3a2b-4d5e-6f78-9abc-def012345678'));
        $this->assertSame('/api/sessions/:uuid/data', RouteFingerprint::normalize('/api/sessions/550e8400-e29b-41d4-a716-446655440000/data'));
    }

    // ── Hex hashes → :hash ─────────────────────────────────

    public function test_hex_hashes_replaced(): void
    {
        // MD5 (32 chars)
        $this->assertSame('/assets/:hash', RouteFingerprint::normalize('/assets/d41d8cd98f00b204e9800998ecf8427e'));
        // SHA256 (64 chars)
        $this->assertSame('/files/:hash', RouteFingerprint::normalize('/files/e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));
    }

    // ── Tokens → :token ────────────────────────────────────

    public function test_mixed_case_tokens_replaced(): void
    {
        $this->assertSame('/api/sessions/:token', RouteFingerprint::normalize('/api/sessions/AbCdEfGhIjKlMnOpQrSt'));
    }

    // ── Query strings stripped ──────────────────────────────

    public function test_query_strings_removed(): void
    {
        $this->assertSame('/search', RouteFingerprint::normalize('/search?q=shoes&page=2'));
        $this->assertSame('/products', RouteFingerprint::normalize('/products?category=electronics&sort=price'));
    }

    // ── Trailing slashes normalized ────────────────────────

    public function test_trailing_slash_removed(): void
    {
        $this->assertSame('/about', RouteFingerprint::normalize('/about/'));
        $this->assertSame('/api/v1', RouteFingerprint::normalize('/api/v1/'));
    }

    // ── Double slashes collapsed ───────────────────────────

    public function test_double_slashes_collapsed(): void
    {
        $this->assertSame('/api/v1/users', RouteFingerprint::normalize('/api//v1///users'));
    }

    // ── Segment cap ────────────────────────────────────────

    public function test_max_segments_capped(): void
    {
        $path = '/a/b/c/d/e/f/g/h/i/j/k';
        $result = RouteFingerprint::normalize($path);

        // Should keep first 8 segments + *
        $this->assertSame('/a/b/c/d/e/f/g/h/*', $result);
    }

    // ── Combined realistic URLs ────────────────────────────

    public function test_realistic_ecommerce_urls(): void
    {
        $this->assertSame('/products/:id', RouteFingerprint::normalize('/products/12345'));
        $this->assertSame('/orders/:uuid/invoice', RouteFingerprint::normalize('/orders/550e8400-e29b-41d4-a716-446655440000/invoice'));
        $this->assertSame('/checkout/:id/confirm', RouteFingerprint::normalize('/checkout/999/confirm'));
    }

    public function test_api_endpoints(): void
    {
        $this->assertSame('/api/v2/users/:id/settings', RouteFingerprint::normalize('/api/v2/users/42/settings'));
        $this->assertSame('/api/proxy-config/:id', RouteFingerprint::normalize('/api/proxy-config/7'));
    }

    // ── Short strings that should NOT be replaced ──────────

    public function test_short_strings_not_replaced(): void
    {
        // API versioning segments should stay
        $this->assertSame('/api/v2/health', RouteFingerprint::normalize('/api/v2/health'));
        // Short alpha strings stay
        $this->assertSame('/en/about', RouteFingerprint::normalize('/en/about'));
    }
}
