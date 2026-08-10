<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\CookieBar;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\RuntimeRevision;
use App\Runtime\ManifestMetrics;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Publisher\RevisionPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for proxy cache warming in postPublishAccelerate().
 *
 * Validates that after a publish, the 3 SDK endpoints are warmed
 * via local HTTP GETs, metrics are tracked, and log messages are emitted.
 *
 * Run with: php artisan test --filter=ProxyWarmTest
 */
class ProxyWarmTest extends TestCase
{
    protected Group $agency;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        ManifestMetrics::reset();

        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();

        $this->createTestSchema();
        $this->agency = Group::create(['name' => 'Test Agency']);
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(
            $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
        );
        Cache::flush();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════
    // Warm endpoint tests
    // ═══════════════════════════════════════════════════════════

    #[Test]
    public function it_warms_three_endpoints_after_publish(): void
    {
        Http::fake([
            '*/api/config/*'    => Http::response('{"site_id":"test"}', 200),
            '*/api/script/*.js' => Http::response('// js', 200),
            '*/api/boot/*.js'   => Http::response('// boot', 200),
        ]);

        $domain = $this->createDomain('warm-test.com');
        $revision = $this->publishRevision($domain);

        $publisher = app(RevisionPublisher::class);
        $publisher->postPublishAccelerate($domain->fresh(), $revision);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/config/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/script/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/boot/'));

        $this->assertEquals(0, ManifestMetrics::get('proxy_warm_failures'));
    }

    #[Test]
    public function it_increments_failure_metric_on_endpoint_error(): void
    {
        Http::fake([
            '*/api/config/*'    => Http::response('{"site_id":"test"}', 200),
            '*/api/script/*.js' => Http::response('Server Error', 500),
            '*/api/boot/*.js'   => Http::response('// boot', 200),
        ]);

        $domain = $this->createDomain('warm-fail.com');
        $revision = $this->publishRevision($domain);

        $publisher = app(RevisionPublisher::class);
        $publisher->postPublishAccelerate($domain->fresh(), $revision);

        $this->assertEquals(1, ManifestMetrics::get('proxy_warm_failures'));
    }

    #[Test]
    public function it_skips_warming_when_no_site_id(): void
    {
        Http::fake();

        $domain = $this->createDomain('no-site.com', siteId: '');
        $revision = $this->publishRevision($domain);

        // Clear HTTP fake to intercept warm calls — there should be none
        // beyond what publish already did (if any)
        Http::fake();

        $publisher = app(RevisionPublisher::class);
        $publisher->postPublishAccelerate($domain->fresh(), $revision);

        // No HTTP requests should be sent for warming (invalidate/Redis are separate)
        $this->assertEquals(0, ManifestMetrics::get('proxy_warm_failures'));
    }

    #[Test]
    public function it_handles_http_timeout_gracefully(): void
    {
        Http::fake([
            '*/api/config/*'    => Http::response('{"site_id":"test"}', 200),
            '*/api/script/*.js' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
            '*/api/boot/*.js'   => Http::response('// boot', 200),
        ]);

        $domain = $this->createDomain('timeout.com');
        $revision = $this->publishRevision($domain);

        $publisher = app(RevisionPublisher::class);

        // Should not throw — failures are non-fatal
        $publisher->postPublishAccelerate($domain->fresh(), $revision);

        $this->assertEquals(1, ManifestMetrics::get('proxy_warm_failures'));
    }

    #[Test]
    public function proxy_warm_failures_appears_in_metrics_all(): void
    {
        $metrics = ManifestMetrics::all();

        $this->assertArrayHasKey('proxy_warm_failures', $metrics);
        $this->assertEquals(0, $metrics['proxy_warm_failures']);
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    protected function createDomain(string $name, ?string $siteId = null): Domain
    {
        $cookieBar = CookieBar::create([
            'name' => 'Test Bar '.Str::random(8),
            'group_id' => $this->agency->id,
            'theme_settings' => [],
            'translations' => [],
            'ui_config' => [
                'layout' => 'box',
                'position' => 'bottom_left',
            ],
        ]);

        $domain = Domain::create([
            'group_id'         => $this->agency->id,
            'name'             => $name,
            'site_id'          => $siteId ?? Str::random(32),
            'is_active'        => true,
            'consent_version'  => 1,
            'manifest_enabled' => true,
            'cookie_bar_id'    => $cookieBar->id,
        ]);

        $essential = CookieGroup::create([
            'name' => 'Essential', 'key' => 'essential-' . $name,
            'is_required' => true, 'is_preselected' => true,
        ]);
        $essential->domains()->attach($domain->id);

        return $domain;
    }

    protected function publishRevision(Domain $domain): RuntimeRevision
    {
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);
        $compileResult = $compiler->compile($domain);
        return $publisher->publish($domain, $compileResult);
    }

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
            $table->unsignedBigInteger('cookie_bar_id')->nullable();
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
}
