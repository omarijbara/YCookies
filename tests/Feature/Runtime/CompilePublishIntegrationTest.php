<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\CookieGroup;
use App\Models\ContentBlocker;
use App\Models\Domain;
use App\Models\Group;
use App\Models\RuntimeRevision;
use App\Models\ScriptBlocker;
use App\Models\Service;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Consumer\EffectivePolicyBuilder;
use App\Runtime\Consumer\RevisionResolver;
use App\Runtime\Publisher\RevisionPublisher;
use App\Runtime\Publisher\RevisionSigner;
use App\Runtime\Schema\ManifestSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integration tests for the compile → sign → publish → resolve → consume pipeline.
 *
 * These tests exercise the full DB-backed lifecycle:
 *   - DomainCompiler reads real models → produces artifacts
 *   - RevisionPublisher transactionally persists and moves the pointer
 *   - RevisionResolver reads back the active revision
 *   - EffectivePolicyBuilder serves the effective config
 *   - Pointer rollback works deterministically
 *   - Input dedup skips no-op publishes
 *
 * Uses SQLite in-memory with focused schema setup (skips MySQL-only migrations).
 * Run with: php artisan test --filter=CompilePublishIntegrationTest
 */
class CompilePublishIntegrationTest extends TestCase
{
    protected Group $agency;
    protected Domain $domain;
    protected DomainCompiler $compiler;
    protected RevisionPublisher $publisher;
    protected RevisionResolver $resolver;
    protected ?Service $marketingService = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();

        // Disable ALL Eloquent events — prevents observer chains from
        // querying tables not in our focused schema
        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();

        // Create focused schema for runtime tests (avoids MySQL-only migration chain)
        $this->createTestSchema();

        $this->agency = Group::create(['name' => 'Test Agency']);

        $this->domain = Domain::create([
            'group_id'         => $this->agency->id,
            'name'             => 'integration-test.com',
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'consent_version'  => 1,
            'manifest_enabled' => true,
        ]);

        $this->compiler = app(DomainCompiler::class);
        $this->publisher = app(RevisionPublisher::class);
        $this->resolver = app(RevisionResolver::class);
    }

    protected function tearDown(): void
    {
        // Re-enable events for other tests
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(
            $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
        );
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Create only the tables needed for runtime integration tests.
     * This avoids the full migration chain which contains MySQL-only syntax.
     */
    protected function createTestSchema(): void
    {
        // Skip if tables already exist (LazilyRefreshDatabase)
        if (Schema::hasTable('groups')) {
            return;
        }

        // Core tables
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('groups', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('domains', function ($table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups');
            $table->string('name');
            $table->string('site_id', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('consent_version')->default(1);
            $table->unsignedBigInteger('active_revision_id')->nullable();
            $table->boolean('manifest_enabled')->default(false);
            $table->boolean('proxy_enabled')->default(false);
            $table->string('origin_url')->nullable();
            $table->json('consent_mode_mapping')->nullable();
            $table->boolean('consent_mode_enabled')->default(true);
            $table->boolean('advanced_consent_mode')->default(false);
            $table->json('tcf_config')->nullable();
            $table->timestamps();
        });

        Schema::create('cookie_groups', function ($table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('name');
            $table->string('key');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_preselected')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cookie_group_domain', function ($table) {
            $table->id();
            $table->foreignId('cookie_group_id')->constrained('cookie_groups');
            $table->foreignId('domain_id')->constrained('domains');
        });

        Schema::create('services', function ($table) {
            $table->id();
            $table->foreignId('cookie_group_id')->constrained('cookie_groups');
            $table->string('name');
            $table->string('key');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->string('purpose')->nullable();
            $table->string('privacy_policy_url')->nullable();
            $table->string('template_key')->nullable();
            $table->string('template_version')->nullable();
            $table->json('cookie_names')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('integration_type')->nullable();
            $table->string('provider_key')->nullable();
            $table->json('domains')->nullable();
            $table->json('consent_mode_mapping')->nullable();
            $table->json('blocking_rules')->nullable();
            $table->boolean('supports_accept_once')->default(false);
            $table->boolean('supports_accept_provider')->default(false);
            $table->json('ui_config')->nullable();
            $table->json('compliance')->nullable();
            $table->json('test_manifest')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('providers', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('key')->nullable();
            $table->string('privacy_policy_url')->nullable();
            $table->boolean('is_library')->default(false);
            $table->timestamps();
        });

        Schema::create('service_cookies', function ($table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services');
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->string('duration')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('domain_service', function ($table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains');
            $table->foreignId('service_id')->constrained('services');
        });

        Schema::create('script_blockers', function ($table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains');
            $table->foreignId('service_id')->nullable()->constrained('services');
            $table->string('name');
            $table->string('key');
            $table->json('handles')->nullable();
            $table->json('phrases')->nullable();
            $table->string('on_exist')->default('change_type');
            $table->boolean('is_active')->default(true);
            $table->string('cookie_group_key')->nullable();
            $table->timestamps();
        });

        Schema::create('content_blockers', function ($table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains');
            $table->foreignId('service_id')->nullable()->constrained('services');
            $table->string('name');
            $table->string('key');
            $table->json('hosts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('preview_image_url')->nullable();
            $table->text('html_code')->nullable();
            $table->text('css_code')->nullable();
            $table->text('js_code')->nullable();
            $table->json('text_placeholders')->nullable();
            $table->string('cookie_group_key')->nullable();
            $table->text('placeholder_text')->nullable();
            $table->timestamps();
        });

        Schema::create('cookie_bars', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->json('theme_settings')->nullable();
            $table->json('translations')->nullable();
            $table->json('ui_config')->nullable();
            $table->timestamps();
        });

        // Runtime manifest tables
        Schema::create('runtime_revisions', function ($table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_number');
            $table->string('schema_version', 16)->default('1.0.0');
            $table->string('status')->default('draft');
            $table->longText('manifest_json');
            $table->string('manifest_hash', 64);
            $table->text('manifest_signature')->nullable();
            $table->longText('base_artifact_json');
            $table->string('base_artifact_hash', 64);
            $table->longText('route_index_json')->nullable();
            $table->string('route_index_hash', 64)->nullable();
            $table->foreignId('compiled_by')->nullable();
            $table->string('compile_inputs_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'revision_number']);
        });

        Schema::create('runtime_overlays', function ($table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('runtime_revisions')->cascadeOnDelete();
            $table->string('overlay_id');
            $table->string('route_pattern');
            $table->longText('overlay_json');
            $table->string('overlay_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['revision_id', 'overlay_id']);
        });

        // Activity log (Spatie)
        Schema::create('activity_log', function ($table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    // ═══ Helpers ════════════════════════════════════════════════

    protected function seedCookieGroups(): void
    {
        $essential = CookieGroup::create([
            'name'           => 'Essential',
            'key'            => 'essential',
            'group_id'       => $this->agency->id,
            'is_required'    => true,
            'is_preselected' => true,
        ]);
        $essential->domains()->attach($this->domain->id);

        Service::create([
            'cookie_group_id' => $essential->id,
            'name' => 'Strictly Necessary',
            'key' => 'strictly-necessary',
            'is_active' => true,
        ]);

        $marketing = CookieGroup::create([
            'name'           => 'Marketing',
            'key'            => 'marketing',
            'group_id'       => $this->agency->id,
            'is_required'    => false,
            'is_preselected' => false,
        ]);
        $marketing->domains()->attach($this->domain->id);

        $this->marketingService = Service::create([
            'cookie_group_id' => $marketing->id,
            'name'            => 'Google Analytics',
            'key'             => 'google-analytics',
            'is_active'       => true,
        ]);
    }

    protected function seedBlockers(): void
    {
        $service = $this->marketingService ?? Service::query()->first();

        ScriptBlocker::create([
            'domain_id'  => $this->domain->id,
            'service_id' => $service?->id,
            'name'       => 'GA Blocker',
            'key'        => 'ga-blocker',
            'handles'    => ['googletagmanager.com/gtag'],
            'phrases'    => [],
            'on_exist'   => 'change_type',
            'is_active'  => true,
        ]);

        ContentBlocker::create([
            'domain_id'         => $this->domain->id,
            'service_id'        => $service?->id,
            'name'              => 'YouTube Blocker',
            'key'               => 'youtube-blocker',
            'hosts'             => ['youtube.com', 'youtube-nocookie.com'],
            'is_active'         => true,
            'preview_image_url' => 'https://cdn.example.test/youtube-preview.jpg',
            'html_code'         => '<div>Preview</div>',
            'css_code'          => '.preview { display: block; }',
            'js_code'           => 'window.previewLoaded = true;',
            'text_placeholders' => ['headline' => 'Click to load video'],
        ]);
    }

    // ═══ Core Pipeline ═════════════════════════════════════════

    public function test_compile_produces_valid_base_artifact(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);

        // Base artifact has required fields
        $this->assertSame($this->domain->site_id, $result->baseArtifact['site_id']);
        $this->assertSame('integration-test.com', $result->baseArtifact['domain']);
        $this->assertIsArray($result->baseArtifact['cookie_groups']);
        $this->assertIsArray($result->baseArtifact['ui_config']);
        $this->assertIsArray($result->baseArtifact['features']);

        // No forbidden fields
        $violations = ManifestSchema::validateNoForbiddenFields($result->baseArtifact);
        $this->assertEmpty($violations, 'Base artifact has forbidden fields: ' . implode(', ', $violations));

        // Hashes are correct
        $expectedHash = hash('sha256', $result->baseArtifactJson);
        $this->assertSame($expectedHash, $result->baseArtifactHash);

        // Manifest envelope is valid
        $manifestViolations = ManifestSchema::validateManifest($result->manifest);
        $this->assertEmpty($manifestViolations, 'Manifest validation failed: ' . implode(', ', $manifestViolations));
    }

    public function test_compile_includes_cookie_groups_with_services(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);

        $groups = $result->baseArtifact['cookie_groups'];
        $this->assertCount(2, $groups, 'Should have essential + marketing groups');

        $essential = collect($groups)->firstWhere('key', 'essential');
        $marketing = collect($groups)->firstWhere('key', 'marketing');

        $this->assertTrue($essential['is_required']);
        $this->assertFalse($marketing['is_required']);
        $this->assertCount(1, $marketing['services'], 'Marketing should have GA service');
        $this->assertSame('Google Analytics', $marketing['services'][0]['name']);
    }

    public function test_compile_includes_blockers(): void
    {
        $this->seedCookieGroups();
        $this->seedBlockers();
        $result = $this->compiler->compile($this->domain);

        $this->assertCount(1, $result->baseArtifact['script_blockers']);
        $this->assertSame('ga-blocker', $result->baseArtifact['script_blockers'][0]['key']);
        $this->assertSame('google-analytics', $result->baseArtifact['script_blockers'][0]['service']);

        $this->assertCount(1, $result->baseArtifact['content_blockers']);
        $this->assertSame('youtube-blocker', $result->baseArtifact['content_blockers'][0]['key']);
        $this->assertSame('google-analytics', $result->baseArtifact['content_blockers'][0]['service']);
        $this->assertSame(
            'https://cdn.example.test/youtube-preview.jpg',
            $result->baseArtifact['content_blockers'][0]['preview_image']
        );
    }

    public function test_publish_creates_revision_and_moves_pointer(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $revision = $this->publisher->publish($this->domain, $result);

        $this->assertInstanceOf(RuntimeRevision::class, $revision);
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame('published', $revision->status);
        $this->assertNotNull($revision->published_at);
        $this->assertNotNull($revision->manifest_signature);
        $this->assertSame(ManifestSchema::SCHEMA_VERSION, $revision->schema_version);

        // Domain pointer moved
        $freshDomain = $this->domain->fresh();
        $this->assertSame($revision->id, $freshDomain->active_revision_id);
    }

    public function test_publish_increments_revision_number(): void
    {
        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);
        $rev1 = $this->publisher->publish($this->domain, $result1);
        $this->assertSame(1, $rev1->revision_number);

        $result2 = $this->compiler->compile($this->domain);
        $rev2 = $this->publisher->publish($this->domain, $result2);
        $this->assertSame(2, $rev2->revision_number);

        $result3 = $this->compiler->compile($this->domain);
        $rev3 = $this->publisher->publish($this->domain, $result3);
        $this->assertSame(3, $rev3->revision_number);

        $this->assertSame($rev3->id, $this->domain->fresh()->active_revision_id);
    }

    public function test_signature_verifies(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $revision = $this->publisher->publish($this->domain, $result);

        $signer = app(RevisionSigner::class);
        $manifest = $revision->getManifest();
        $signature = $revision->manifest_signature;

        $this->assertTrue(
            $signer->verify($manifest, $signature),
            'Published manifest signature should verify'
        );

        // Tampered manifest should fail
        $tampered = $manifest;
        $tampered['revision'] = 999;
        $this->assertFalse(
            $signer->verify($tampered, $signature),
            'Tampered manifest should fail verification'
        );
    }

    // ═══ Resolver ══════════════════════════════════════════════

    public function test_resolver_returns_active_revision(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $revision = $this->publisher->publish($this->domain, $result);

        Cache::flush();
        $resolved = $this->resolver->resolveActive('integration-test.com');

        $this->assertNotNull($resolved);
        $this->assertSame($revision->revision_number, $resolved->revisionNumber);
        $this->assertSame('integration-test.com', $resolved->domainName);
        $this->assertSame($this->domain->site_id, $resolved->baseArtifact['site_id']);
    }

    public function test_resolver_returns_null_for_non_manifest_domain(): void
    {
        Domain::create([
            'group_id'         => $this->agency->id,
            'name'             => 'old-domain.com',
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'manifest_enabled' => false,
        ]);

        $resolved = $this->resolver->resolveActive('old-domain.com');
        $this->assertNull($resolved, 'Non-manifest-enabled domain should return null');
    }

    public function test_resolver_returns_null_for_unknown_domain(): void
    {
        $resolved = $this->resolver->resolveActive('nonexistent.example.com');
        $this->assertNull($resolved);
    }

    public function test_resolver_caches_result(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $this->publisher->publish($this->domain, $result);

        $resolved1 = $this->resolver->resolveActive('integration-test.com');
        $this->assertNotNull($resolved1);

        // Delete from DB; should still serve from cache
        RuntimeRevision::query()->delete();
        $resolved2 = $this->resolver->resolveActive('integration-test.com');
        $this->assertNotNull($resolved2, 'Should serve from cache even after DB deletion');
    }

    public function test_resolver_invalidation(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $this->publisher->publish($this->domain, $result);

        $this->resolver->resolveActive('integration-test.com');
        $this->resolver->invalidate('integration-test.com');

        RuntimeRevision::query()->delete();
        $resolved = $this->resolver->resolveActive('integration-test.com');
        $this->assertNull($resolved, 'After invalidation, should fall through to DB');
    }

    // ═══ Rollback ══════════════════════════════════════════════

    public function test_rollback_moves_pointer_to_previous_revision(): void
    {
        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);
        $rev1 = $this->publisher->publish($this->domain, $result1);

        $result2 = $this->compiler->compile($this->domain);
        $rev2 = $this->publisher->publish($this->domain, $result2);

        $this->assertSame($rev2->id, $this->domain->fresh()->active_revision_id);

        $rolledBack = $this->publisher->rollback($this->domain, 1);
        $this->assertSame(1, $rolledBack->revision_number);
        $this->assertSame($rev1->id, $this->domain->fresh()->active_revision_id);
        $this->assertSame('rolled_back', $rev2->fresh()->status);
    }

    public function test_rollback_to_nonexistent_revision_fails(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $this->publisher->publish($this->domain, $result);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->publisher->rollback($this->domain, 999);
    }

    // ═══ Input Dedup ═══════════════════════════════════════════

    public function test_compile_inputs_hash_is_deterministic(): void
    {
        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);
        $result2 = $this->compiler->compile($this->domain);

        $this->assertSame(
            $result1->compileInputsHash,
            $result2->compileInputsHash,
            'Same inputs should produce same compile_inputs_hash'
        );
    }

    public function test_compile_inputs_hash_changes_on_model_change(): void
    {
        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);

        CookieGroup::where('key', 'marketing')->update(['name' => 'Modified Marketing']);
        $this->domain->refresh();

        $result2 = $this->compiler->compile($this->domain);

        $this->assertNotSame(
            $result1->compileInputsHash,
            $result2->compileInputsHash,
            'Changed inputs should produce different compile_inputs_hash'
        );
    }

    // ═══ Effective Policy Builder ══════════════════════════════

    public function test_effective_policy_returns_base_for_no_overlays(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $this->publisher->publish($this->domain, $result);

        Cache::flush();
        $resolved = $this->resolver->resolveActive('integration-test.com');

        $builder = new EffectivePolicyBuilder();
        $policy = $builder->build($resolved, '/about');

        $this->assertSame($this->domain->site_id, $policy['site_id']);
        $this->assertCount(2, $policy['cookie_groups']);
    }

    // ═══ Artifact Hash Integrity ═══════════════════════════════

    public function test_stored_hash_matches_recomputed_hash(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $revision = $this->publisher->publish($this->domain, $result);

        $storedJson = $revision->base_artifact_json;
        $recomputedHash = hash('sha256', $storedJson);

        $this->assertSame(
            $revision->base_artifact_hash,
            $recomputedHash,
            'Stored base_artifact_hash must match recomputed hash of stored JSON'
        );
    }

    public function test_canonical_json_is_deterministic(): void
    {
        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);
        $result2 = $this->compiler->compile($this->domain);

        $this->assertSame(
            $result1->baseArtifactJson,
            $result2->baseArtifactJson,
            'Canonical JSON should be identical for same inputs'
        );
    }

    // ═══ No Forbidden Fields ═══════════════════════════════════

    public function test_compiled_artifact_never_contains_visitor_country(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);

        $json = $result->baseArtifactJson;
        $this->assertStringNotContainsString('visitor_country', $json);
        $this->assertStringNotContainsString('raw_ip', $json);
    }

    // ═══ Schema Version ════════════════════════════════════════

    public function test_revision_carries_schema_version(): void
    {
        $this->seedCookieGroups();
        $result = $this->compiler->compile($this->domain);
        $revision = $this->publisher->publish($this->domain, $result);

        $this->assertSame('1.0.0', $revision->schema_version);

        $manifest = $revision->getManifest();
        $this->assertSame('1.0.0', $manifest['schema_version']);
    }

    // ═══ Multiple Domains ══════════════════════════════════════

    public function test_revisions_are_scoped_to_domain(): void
    {
        $domain2 = Domain::create([
            'group_id'         => $this->agency->id,
            'name'             => 'other-domain.com',
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'manifest_enabled' => true,
        ]);

        $this->seedCookieGroups();

        $result1 = $this->compiler->compile($this->domain);
        $rev1 = $this->publisher->publish($this->domain, $result1);

        $result2 = $this->compiler->compile($domain2);
        $rev2 = $this->publisher->publish($domain2, $result2);

        $this->assertSame(1, $rev1->revision_number);
        $this->assertSame(1, $rev2->revision_number);

        $this->assertSame($rev1->id, $this->domain->fresh()->active_revision_id);
        $this->assertSame($rev2->id, $domain2->fresh()->active_revision_id);
    }
}
