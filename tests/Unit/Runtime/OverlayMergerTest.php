<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use App\Runtime\Schema\ManifestSchema;
use App\Runtime\Schema\OverlayMerger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OverlayMerger — deterministic base + overlay merge.
 *
 * These test cases also serve as the merge specification:
 * - Overlays are sparse (only overridden fields)
 * - List arrays (cookie_groups, script_blockers, content_blockers) are replaced
 * - Associative arrays (ui_config, translations, features) are deep-merged
 * - Null values explicitly remove base keys
 * - Base-only fields are never overridden
 */
class OverlayMergerTest extends TestCase
{
    private array $baseArtifact;
    private array $overlayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $fixtureDir = dirname(__DIR__, 2) . '/Fixtures/Runtime';
        $this->baseArtifact = json_decode(file_get_contents("{$fixtureDir}/base_artifact_v1.json"), true);
        $this->overlayFixture = json_decode(file_get_contents("{$fixtureDir}/overlay_simple_v1.json"), true);
    }

    // ─── Null Overlay (base-only) ──────────────────────────────────

    public function test_null_overlay_returns_base(): void
    {
        $result = OverlayMerger::merge($this->baseArtifact, null);
        $this->assertSame($this->baseArtifact, $result);
    }

    public function test_empty_overlay_returns_base(): void
    {
        $result = OverlayMerger::merge($this->baseArtifact, []);
        $this->assertSame($this->baseArtifact, $result);
    }

    // ─── Golden Fixture Merge ──────────────────────────────────────

    public function test_golden_overlay_merge(): void
    {
        $result = OverlayMerger::merge($this->baseArtifact, $this->overlayFixture);

        // ui_config should be deep-merged
        $this->assertSame('bottom_bar', $result['ui_config']['layout']);
        $this->assertSame('bottom', $result['ui_config']['position']);
        // Non-overridden ui_config fields should survive
        $this->assertSame('#3b82f6', $result['ui_config']['colors']['primary']);

        // features should be deep-merged
        $this->assertFalse($result['features']['lna_shield']);
        // geo_restriction_eu from features should survive
        $this->assertFalse($result['features']['geo_restriction_eu']);
    }

    // ─── Deep Merge (associative arrays) ───────────────────────────

    public function test_deep_merge_ui_config(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'ui_config' => [
                'colors' => ['primary' => '#ff0000'],
            ],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);

        // Overridden field
        $this->assertSame('#ff0000', $result['ui_config']['colors']['primary']);
        // Non-overridden fields preserved
        $this->assertSame('#111827', $result['ui_config']['colors']['background']);
        $this->assertSame('box_modal', $result['ui_config']['layout']);
    }

    public function test_deep_merge_translations(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'translations' => [
                'banner' => ['title' => 'New Title'],
            ],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);

        $this->assertSame('New Title', $result['translations']['banner']['title']);
        // Description should survive
        $this->assertSame('We use cookies to improve your experience.', $result['translations']['banner']['description']);
    }

    // ─── List Array Replacement ────────────────────────────────────

    public function test_cookie_groups_replaced_entirely(): void
    {
        $newGroups = [
            ['key' => 'essential', 'name' => 'Only Essential', 'services' => [], 'is_required' => true, 'is_preselected' => true, 'description' => ''],
        ];
        $overlay = [
            'overlay_id' => 'test',
            'cookie_groups' => $newGroups,
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);

        $this->assertCount(1, $result['cookie_groups'], 'cookie_groups should be replaced, not merged');
        $this->assertSame('Only Essential', $result['cookie_groups'][0]['name']);
    }

    public function test_script_blockers_replaced_entirely(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'script_blockers' => [],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertEmpty($result['script_blockers'], 'Empty overlay list should replace non-empty base list');
    }

    public function test_content_blockers_replaced_entirely(): void
    {
        $newBlockers = [
            ['key' => 'new-blocker', 'name' => 'New', 'hosts' => ['new.com']],
        ];
        $overlay = [
            'overlay_id' => 'test',
            'content_blockers' => $newBlockers,
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);

        $this->assertCount(1, $result['content_blockers']);
        $this->assertSame('new-blocker', $result['content_blockers'][0]['key']);
    }

    // ─── Null Removal ──────────────────────────────────────────────

    public function test_null_removes_base_key(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'geo_rules' => null,
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertArrayNotHasKey('geo_rules', $result);
    }

    public function test_null_in_deep_merge_removes_nested_key(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'ui_config' => [
                'effects' => null,
            ],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertArrayNotHasKey('effects', $result['ui_config']);
        // Other ui_config keys should survive
        $this->assertSame('box_modal', $result['ui_config']['layout']);
    }

    // ─── Base-Only Field Protection ────────────────────────────────

    public function test_base_only_field_site_id_not_overridden(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'site_id' => 'hacked-id',
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertSame('abc-123-def', $result['site_id']);
    }

    public function test_base_only_field_domain_not_overridden(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'domain' => 'evil.com',
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertSame('example.com', $result['domain']);
    }

    public function test_base_only_field_callbacks_not_overridden(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'callbacks' => ['onReady' => 'alert("xss")'],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertStringContainsString('ycookiesDispatchEvent', $result['callbacks']['onReady']);
    }

    // ─── Non-Eligible Fields Ignored ───────────────────────────────

    public function test_non_eligible_fields_ignored(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'random_field' => 'should be ignored',
            'origin' => ['url' => 'https://evil.com'],  // not overlay-eligible
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertArrayNotHasKey('random_field', $result);
        // origin should remain unchanged (not overlay-eligible)
        $this->assertSame('https://origin.example.com', $result['origin']['url']);
    }

    // ─── overlay_id Meta Field ─────────────────────────────────────

    public function test_overlay_id_not_injected_into_result(): void
    {
        $overlay = [
            'overlay_id' => 'test_overlay',
            'ui_config' => ['layout' => 'bottom_bar'],
        ];
        $result = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertArrayNotHasKey('overlay_id', $result);
    }

    // ─── Sequential Array Detection ────────────────────────────────

    public function test_sequential_array_detection(): void
    {
        $this->assertTrue(OverlayMerger::isSequentialArray([1, 2, 3]));
        $this->assertTrue(OverlayMerger::isSequentialArray(['a', 'b', 'c']));
        $this->assertTrue(OverlayMerger::isSequentialArray([]));
        $this->assertFalse(OverlayMerger::isSequentialArray(['key' => 'value']));
        $this->assertFalse(OverlayMerger::isSequentialArray([0 => 'a', 2 => 'c']));
    }

    // ─── Idempotency ───────────────────────────────────────────────

    public function test_merge_is_idempotent(): void
    {
        $overlay = [
            'overlay_id' => 'test',
            'ui_config' => ['layout' => 'bottom_bar'],
            'features' => ['lna_shield' => false],
        ];

        $result1 = OverlayMerger::merge($this->baseArtifact, $overlay);
        $result2 = OverlayMerger::merge($this->baseArtifact, $overlay);
        $this->assertSame($result1, $result2);
    }

    public function test_merge_does_not_mutate_inputs(): void
    {
        $base = $this->baseArtifact;
        $overlay = $this->overlayFixture;
        $baseJson = json_encode($base);
        $overlayJson = json_encode($overlay);

        OverlayMerger::merge($base, $overlay);

        $this->assertSame($baseJson, json_encode($base), 'Base should not be mutated');
        $this->assertSame($overlayJson, json_encode($overlay), 'Overlay should not be mutated');
    }
}
