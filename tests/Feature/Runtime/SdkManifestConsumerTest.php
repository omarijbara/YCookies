<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Language;
use App\Models\RuntimeRevision;
use App\Models\ScriptBlocker;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Consumer\ManifestConfigService;
use App\Runtime\Consumer\RevisionResolver;
use App\Runtime\Publisher\RevisionPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for SDK manifest-path consumers:
 *   - ManifestConfigService::resolveConfig() (used by ScriptDelivery + Projection)
 *   - ManifestConfigService::resolveBlocklist() (used by Bootstrapper)
 *   - Legacy fallback behavior when manifest is disabled
 *
 * Uses SQLite in-memory with focused schema setup.
 * Run with: php artisan test --filter=SdkManifestConsumerTest
 */
class SdkManifestConsumerTest extends TestCase
{
    protected Group $agency;
    protected Domain $domain;
    protected ManifestConfigService $configService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();

        // Disable ALL Eloquent events — prevents observer chains from
        // querying tables not in our focused schema
        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();

        $this->createTestSchema();

        $this->agency = Group::create(['name' => 'Test Agency']);

        $this->domain = Domain::create([
            'group_id'         => $this->agency->id,
            'name'             => 'sdk-test.example.com',
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'consent_version'  => 1,
            'manifest_enabled' => true,
        ]);

        $this->configService = app(ManifestConfigService::class);
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(
            $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
        );
        Cache::flush();
        parent::tearDown();
    }

    // ── resolveConfig Tests ──────────────────────────────────────────────

    #[Test]
    public function resolve_config_returns_null_when_manifest_disabled(): void
    {
        $this->domain->update(['manifest_enabled' => false]);

        $result = $this->configService->resolveConfig($this->domain, 'en');

        $this->assertNull($result, 'Should return null when manifest_enabled is false');
    }

    #[Test]
    public function resolve_config_returns_null_when_no_revision_published(): void
    {
        // manifest_enabled=true but no revision published
        $result = $this->configService->resolveConfig($this->domain, 'en');

        $this->assertNull($result, 'Should return null when no revision is published');
    }

    #[Test]
    public function resolve_config_returns_projected_array_from_manifest(): void
    {
        $this->publishRevision();

        $result = $this->configService->resolveConfig($this->domain, 'en');

        $this->assertNotNull($result, 'Should return config array after publishing');
        $this->assertIsArray($result);

        // Verify core fields are present
        $this->assertArrayHasKey('site_id', $result);
        $this->assertArrayHasKey('domain', $result);
        $this->assertArrayHasKey('cookie_groups', $result);
        $this->assertArrayHasKey('script_blockers', $result);
        $this->assertArrayHasKey('content_blockers', $result);
        $this->assertArrayHasKey('callbacks', $result);
        $this->assertArrayHasKey('_manifest_revision', $result);
        $this->assertEquals(1, $result['_manifest_revision']);
    }

    #[Test]
    public function resolve_config_includes_version_and_domain_fields(): void
    {
        $this->publishRevision();

        $result = $this->configService->resolveConfig($this->domain, 'en');

        $this->assertEquals('1.0.0', $result['version']);
        $this->assertEquals($this->domain->site_id, $result['site_id']);
        $this->assertEquals('sdk-test.example.com', $result['domain']);
    }

    #[Test]
    public function resolve_config_is_cached_by_revision_number(): void
    {
        $this->publishRevision();

        // First call resolves from DB
        $result1 = $this->configService->resolveConfig($this->domain, 'en');
        $this->assertNotNull($result1);

        // Second call should be cached (same revision)
        $result2 = $this->configService->resolveConfig($this->domain, 'en');
        $this->assertEquals($result1, $result2);
    }

    // ── resolveBlocklist Tests ───────────────────────────────────────────

    #[Test]
    public function resolve_blocklist_returns_null_when_manifest_disabled(): void
    {
        $this->domain->update(['manifest_enabled' => false]);

        $result = $this->configService->resolveBlocklist($this->domain);

        $this->assertNull($result);
    }

    #[Test]
    public function resolve_blocklist_returns_null_when_no_revision(): void
    {
        $result = $this->configService->resolveBlocklist($this->domain);

        $this->assertNull($result);
    }

    #[Test]
    public function resolve_blocklist_returns_empty_when_no_blockers(): void
    {
        $this->publishRevision();

        $result = $this->configService->resolveBlocklist($this->domain);

        $this->assertNotNull($result);
        $this->assertIsArray($result);
        // Empty because no ScriptBlockers were created for this domain
        $this->assertEmpty($result);
    }

    #[Test]
    public function resolve_blocklist_extracts_handles_and_phrases(): void
    {
        // Add a script blocker before compiling
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'name' => 'Google Analytics',
            'key' => 'google-analytics',
            'handles' => ['google-analytics', 'gtag'],
            'phrases' => ['googletagmanager.com', 'google-analytics.com'],
            'is_active' => true,
            'on_exist' => 'change_type',
        ]);

        $this->publishRevision();

        $result = $this->configService->resolveBlocklist($this->domain);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result);

        // Should contain both handles and phrases as patterns
        $patterns = array_column($result, 'pattern');
        $this->assertContains('google-analytics', $patterns);
        $this->assertContains('gtag', $patterns);
        $this->assertContains('googletagmanager.com', $patterns);
        $this->assertContains('google-analytics.com', $patterns);

        // Each entry should have a type
        foreach ($result as $entry) {
            $this->assertArrayHasKey('pattern', $entry);
            $this->assertArrayHasKey('type', $entry);
            $this->assertContains($entry['type'], ['handle', 'phrase']);
        }
    }

    // ── getRevisionNumber Tests ──────────────────────────────────────────

    #[Test]
    public function get_revision_number_returns_null_when_disabled(): void
    {
        $this->domain->update(['manifest_enabled' => false]);

        $this->assertNull($this->configService->getRevisionNumber($this->domain));
    }

    #[Test]
    public function get_revision_number_returns_revision_after_publish(): void
    {
        $this->publishRevision();

        $result = $this->configService->getRevisionNumber($this->domain);

        $this->assertEquals(1, $result);
    }

    // ── Fallback Behavior Tests ──────────────────────────────────────────

    #[Test]
    public function script_delivery_falls_back_to_legacy_when_manifest_disabled(): void
    {
        $this->domain->update(['manifest_enabled' => false]);

        // resolveConfig returns null → ScriptDeliveryController will use legacy path
        $this->assertNull($this->configService->resolveConfig($this->domain, 'en'));
        $this->assertNull($this->configService->resolveBlocklist($this->domain));
    }

    #[Test]
    public function all_methods_return_null_for_unpublished_manifest_domain(): void
    {
        // manifest_enabled = true but nothing published
        $this->assertNull($this->configService->resolveConfig($this->domain, 'en'));
        $this->assertNull($this->configService->resolveBlocklist($this->domain));
        $this->assertNull($this->configService->getRevisionNumber($this->domain));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Compile and publish a revision for the test domain.
     */
    protected function publishRevision(): void
    {
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        $artifacts = $compiler->compile($this->domain);
        $publisher->publish($this->domain, $artifacts);

        // Refresh from DB to pick up active_revision_id
        $this->domain->refresh();
        Cache::flush();
    }

    /**
     * Create only the tables needed for runtime integration tests.
     */
    protected function createTestSchema(): void
    {
        if (Schema::hasTable('groups')) {
            return;
        }

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
            $table->json('service_domains')->nullable();
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
            $table->string('name');
            $table->string('key');
            $table->json('handles')->nullable();
            $table->json('phrases')->nullable();
            $table->string('on_exist')->default('change_type');
            $table->string('cookie_group_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('content_blockers', function ($table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains');
            $table->string('name');
            $table->string('key');
            $table->json('hosts')->nullable();
            $table->string('cookie_group_key')->nullable();
            $table->text('placeholder_text')->nullable();
            $table->boolean('is_active')->default(true);
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

        // Activity log (Spatie)
        Schema::create('activity_log', function ($table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->string('batch_uuid')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
        });

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

        Schema::create('languages', function ($table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_rtl')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed English language for tests
        Language::create(['code' => 'en', 'name' => 'English', 'is_rtl' => false, 'is_active' => true]);
    }
}
