<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use App\Runtime\Schema\ManifestSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestSchema — canonical schema definitions, validation,
 * canonicalization, and golden fixture verification.
 */
class ManifestSchemaTest extends TestCase
{
    private array $manifestFixture;
    private array $baseArtifactFixture;
    private array $routeIndexFixture;
    private array $overlayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $fixtureDir = dirname(__DIR__, 2) . '/Fixtures/Runtime';
        $this->manifestFixture = json_decode(file_get_contents("{$fixtureDir}/manifest_v1.json"), true);
        $this->baseArtifactFixture = json_decode(file_get_contents("{$fixtureDir}/base_artifact_v1.json"), true);
        $this->routeIndexFixture = json_decode(file_get_contents("{$fixtureDir}/route_index_v1.json"), true);
        $this->overlayFixture = json_decode(file_get_contents("{$fixtureDir}/overlay_simple_v1.json"), true);
    }

    // ─── Schema Version ────────────────────────────────────────────

    public function test_schema_version_is_semver(): void
    {
        $version = ManifestSchema::SCHEMA_VERSION;
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function test_schema_version_components_match(): void
    {
        $expected = ManifestSchema::SCHEMA_MAJOR . '.' . ManifestSchema::SCHEMA_MINOR . '.' . ManifestSchema::SCHEMA_PATCH;
        $this->assertSame($expected, ManifestSchema::SCHEMA_VERSION);
    }

    // ─── Manifest Envelope ─────────────────────────────────────────

    public function test_build_manifest_envelope(): void
    {
        $manifest = ManifestSchema::buildManifestEnvelope([
            'domain'    => 'example.com',
            'site_id'   => 'abc-123',
            'revision'  => 1,
            'issued_at' => '2026-03-28T00:00:00Z',
            'artifacts' => [
                'base' => ManifestSchema::buildArtifactRef('abc123', 1024),
            ],
        ]);

        $this->assertSame('1.0.0', $manifest['schema_version']);
        $this->assertSame('example.com', $manifest['domain']);
        $this->assertSame('abc-123', $manifest['site_id']);
        $this->assertSame(1, $manifest['revision']);
        $this->assertNull($manifest['signature']);
        $this->assertArrayHasKey('base', $manifest['artifacts']);
    }

    public function test_validate_manifest_golden_fixture(): void
    {
        $violations = ManifestSchema::validateManifest($this->manifestFixture);
        $this->assertEmpty($violations, 'Golden manifest fixture should validate without violations: ' . implode(', ', $violations));
    }

    public function test_validate_manifest_missing_required_fields(): void
    {
        $violations = ManifestSchema::validateManifest([]);
        $this->assertNotEmpty($violations);
        $this->assertGreaterThanOrEqual(6, count($violations)); // schema_version, domain, site_id, revision, issued_at, artifacts
    }

    public function test_validate_manifest_invalid_schema_version(): void
    {
        $manifest = $this->manifestFixture;
        $manifest['schema_version'] = 'invalid';
        $violations = ManifestSchema::validateManifest($manifest);
        $this->assertContains('Invalid schema_version format: invalid', $violations);
    }

    public function test_validate_manifest_missing_base_artifact(): void
    {
        $manifest = $this->manifestFixture;
        unset($manifest['artifacts']['base']);
        $violations = ManifestSchema::validateManifest($manifest);
        $this->assertContains("Manifest must contain a 'base' artifact reference", $violations);
    }

    public function test_validate_manifest_artifact_missing_hash(): void
    {
        $manifest = $this->manifestFixture;
        unset($manifest['artifacts']['base']['hash']);
        $violations = ManifestSchema::validateManifest($manifest);
        $this->assertContains("Artifact 'base' missing 'hash'", $violations);
    }

    // ─── Base Artifact ─────────────────────────────────────────────

    public function test_build_base_artifact_has_required_fields(): void
    {
        $base = ManifestSchema::buildBaseArtifact([
            'site_id' => 'test-123',
            'domain'  => 'test.com',
        ]);

        $this->assertSame('test-123', $base['site_id']);
        $this->assertSame('test.com', $base['domain']);
        $this->assertSame(1, $base['consent_version']);
        $this->assertFalse($base['cross_domain_enabled']);
        $this->assertIsArray($base['cookie_groups']);
        $this->assertIsArray($base['script_blockers']);
        $this->assertIsArray($base['content_blockers']);
        $this->assertIsArray($base['ui_config']);
        $this->assertIsArray($base['tcm_config']);
    }

    public function test_base_artifact_fixture_has_no_forbidden_fields(): void
    {
        $violations = ManifestSchema::validateNoForbiddenFields($this->baseArtifactFixture);
        $this->assertEmpty($violations, 'Base artifact fixture contains forbidden fields: ' . implode(', ', $violations));
    }

    public function test_forbidden_fields_detected(): void
    {
        $artifact = $this->baseArtifactFixture;
        $artifact['visitor_country'] = 'DE';
        $artifact['raw_ip'] = '1.2.3.4';

        $violations = ManifestSchema::validateNoForbiddenFields($artifact);
        $this->assertCount(2, $violations);
    }

    public function test_forbidden_fields_detected_nested(): void
    {
        $artifact = ['config' => ['visitor_country' => 'US']];
        $violations = ManifestSchema::validateNoForbiddenFields($artifact);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('config.visitor_country', $violations[0]);
    }

    // ─── Route Index ───────────────────────────────────────────────

    public function test_build_route_entry(): void
    {
        $entry = ManifestSchema::buildRouteEntry([
            'pattern'    => '/blog/*',
            'overlay_id' => 'blog_overlay',
            'match_type' => ManifestSchema::MATCH_WILDCARD,
            'priority'   => 5,
        ]);

        $this->assertSame('/blog/*', $entry['pattern']);
        $this->assertSame('blog_overlay', $entry['overlay_id']);
        $this->assertSame('wildcard', $entry['match_type']);
        $this->assertSame(5, $entry['priority']);
    }

    public function test_build_route_index(): void
    {
        $index = ManifestSchema::buildRouteIndex([
            ManifestSchema::buildRouteEntry([
                'pattern'    => '/about',
                'overlay_id' => 'about_overlay',
                'match_type' => ManifestSchema::MATCH_EXACT,
            ]),
        ]);

        $this->assertArrayHasKey('routes', $index);
        $this->assertCount(1, $index['routes']);
    }

    public function test_route_index_fixture_structure(): void
    {
        $this->assertArrayHasKey('routes', $this->routeIndexFixture);
        $this->assertGreaterThan(0, count($this->routeIndexFixture['routes']));

        foreach ($this->routeIndexFixture['routes'] as $route) {
            $this->assertArrayHasKey('pattern', $route);
            $this->assertArrayHasKey('overlay_id', $route);
            $this->assertArrayHasKey('match_type', $route);
            $this->assertArrayHasKey('priority', $route);
        }
    }

    // ─── Overlay ───────────────────────────────────────────────────

    public function test_build_overlay_only_eligible_fields(): void
    {
        $overlay = ManifestSchema::buildOverlay([
            'overlay_id'    => 'test',
            'ui_config'     => ['layout' => 'bottom_bar'],
            'site_id'       => 'should-be-ignored',  // base-only
            'random_field'  => 'should-be-ignored',  // not eligible
        ]);

        $this->assertSame('test', $overlay['overlay_id']);
        $this->assertArrayHasKey('ui_config', $overlay);
        $this->assertArrayNotHasKey('site_id', $overlay);
        $this->assertArrayNotHasKey('random_field', $overlay);
    }

    public function test_validate_overlay_golden_fixture(): void
    {
        $violations = ManifestSchema::validateOverlayFields($this->overlayFixture);
        $this->assertEmpty($violations, 'Golden overlay fixture should validate: ' . implode(', ', $violations));
    }

    public function test_validate_overlay_rejects_base_only_fields(): void
    {
        $overlay = ['overlay_id' => 'test', 'site_id' => 'abc'];
        $violations = ManifestSchema::validateOverlayFields($overlay);
        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('Base-only', $violations[0]);
    }

    // ─── Canonicalization ──────────────────────────────────────────

    public function test_canonicalize_sorts_keys(): void
    {
        $canon = ManifestSchema::canonicalize(['z' => 1, 'a' => 2, 'm' => 3]);
        $decoded = json_decode($canon, true);
        $keys = array_keys($decoded);
        $this->assertSame(['a', 'm', 'z'], $keys);
    }

    public function test_canonicalize_sorts_nested_keys(): void
    {
        $canon = ManifestSchema::canonicalize([
            'z' => ['b' => 1, 'a' => 2],
            'a' => 'hello',
        ]);
        $decoded = json_decode($canon, true);
        $keys = array_keys($decoded);
        $this->assertSame(['a', 'z'], $keys);
        $nestedKeys = array_keys($decoded['z']);
        $this->assertSame(['a', 'b'], $nestedKeys);
    }

    public function test_canonicalize_preserves_list_order(): void
    {
        $canon = ManifestSchema::canonicalize([
            'items' => ['cherry', 'apple', 'banana'],
        ]);
        $decoded = json_decode($canon, true);
        $this->assertSame(['cherry', 'apple', 'banana'], $decoded['items']);
    }

    public function test_canonicalize_no_pretty_print(): void
    {
        $canon = ManifestSchema::canonicalize(['a' => 1]);
        $this->assertStringNotContainsString("\n", $canon);
        $this->assertStringNotContainsString('  ', $canon);
    }

    public function test_canonicalize_unescaped_slashes(): void
    {
        $canon = ManifestSchema::canonicalize(['url' => 'https://example.com/path']);
        $this->assertStringContainsString('https://example.com/path', $canon);
        $this->assertStringNotContainsString('\/', $canon);
    }

    public function test_canonicalize_deterministic(): void
    {
        $data = ['z' => ['b' => [3, 2, 1], 'a' => 'hello'], 'a' => true];
        $first = ManifestSchema::canonicalize($data);
        $second = ManifestSchema::canonicalize($data);
        $this->assertSame($first, $second);
    }

    // ─── Hashing ───────────────────────────────────────────────────

    public function test_hash_artifact_deterministic(): void
    {
        $artifact = ['z' => 1, 'a' => 2];
        $hash1 = ManifestSchema::hashArtifact($artifact);
        $hash2 = ManifestSchema::hashArtifact($artifact);
        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_artifact_different_key_order_same_hash(): void
    {
        $hash1 = ManifestSchema::hashArtifact(['a' => 1, 'b' => 2]);
        $hash2 = ManifestSchema::hashArtifact(['b' => 2, 'a' => 1]);
        $this->assertSame($hash1, $hash2, 'Key order should not affect hash');
    }

    public function test_hash_artifact_is_sha256_hex(): void
    {
        $hash = ManifestSchema::hashArtifact(['test' => true]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    // ─── Artifact Ref ──────────────────────────────────────────────

    public function test_build_artifact_ref(): void
    {
        $ref = ManifestSchema::buildArtifactRef('abc123', 4096);
        $this->assertSame('abc123', $ref['hash']);
        $this->assertSame(4096, $ref['size']);
        $this->assertSame('sha256', $ref['algorithm']);
    }

    // ─── Match Precedence ──────────────────────────────────────────

    public function test_match_precedence_order(): void
    {
        $prec = ManifestSchema::MATCH_PRECEDENCE;
        $this->assertGreaterThan($prec['wildcard'], $prec['exact']);
        $this->assertGreaterThan($prec['globstar'], $prec['wildcard']);
        $this->assertGreaterThan($prec['default'], $prec['globstar']);
    }
}
