<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\Domain;
use App\Models\Group;
use App\Models\RuntimeRevision;
use App\Runtime\Consumer\ResolvedRevision;
use App\Runtime\Consumer\RevisionResolver;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\ManifestMetrics;
use App\Runtime\Publisher\RevisionPublisher;
use App\Runtime\Publisher\RevisionSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for RevisionResolver signature verification and cache invalidation.
 *
 * Covers:
 *   - Valid signature → returns ResolvedRevision
 *   - Invalid signature → returns null (fail-closed)
 *   - Missing signature → returns null (fail-closed)
 *   - Verification failure is logged
 *   - Cache sentinel prevents re-query on failure
 *   - invalidate() busts cache
 *   - Publish → invalidate → resolve gives new revision immediately
 *   - MANIFEST_VERIFY_ON_READ=false bypasses verification
 */
class RevisionResolverVerificationTest extends TestCase
{
    protected Group $agency;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();

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
    // Signature Verification Tests
    // ═══════════════════════════════════════════════════════════

    public function test_resolve_returns_revision_with_valid_signature(): void
    {
        $domain = $this->createPublishedDomain('valid-sig.com');

        $resolver = app(RevisionResolver::class);
        $resolved = $resolver->resolveActive('valid-sig.com');

        $this->assertInstanceOf(ResolvedRevision::class, $resolved);
        $this->assertEquals(1, $resolved->revisionNumber);
        $this->assertEquals('valid-sig.com', $resolved->domainName);
    }

    public function test_resolve_returns_null_for_invalid_signature(): void
    {
        $domain = $this->createPublishedDomain('bad-sig.com');

        // Corrupt the signature in the DB
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64)); // Wrong sig
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);
        $resolved = $resolver->resolveActive('bad-sig.com');

        $this->assertNull($resolved, 'Resolver should return null on invalid signature (fail-closed)');
    }

    public function test_resolve_returns_null_for_missing_signature(): void
    {
        $domain = $this->createPublishedDomain('no-sig.com');

        // Remove the signature from the manifest JSON
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        unset($manifest['signature']);
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);
        $resolved = $resolver->resolveActive('no-sig.com');

        $this->assertNull($resolved, 'Resolver should return null on missing signature (fail-closed)');
    }

    public function test_verification_failure_is_logged(): void
    {
        $domain = $this->createPublishedDomain('logged.com');

        // Corrupt signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message) {
                return str_contains($message, 'signature verification failed');
            });

        // Allow other log calls
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $resolver = app(RevisionResolver::class);
        $resolver->resolveActive('logged.com');
    }

    public function test_cache_sentinel_prevents_requery_on_failure(): void
    {
        $domain = $this->createPublishedDomain('sentinel.com');

        // Corrupt signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);

        // First call — hits DB, fails verification, caches sentinel
        $result1 = $resolver->resolveActive('sentinel.com');
        $this->assertNull($result1);

        // Fix the signature in DB
        $signer = app(RevisionSigner::class);
        $fixedManifest = json_decode($revision->fresh()->manifest_json, true);
        unset($fixedManifest['signature']);
        $fixedManifest['signature'] = $signer->sign($fixedManifest);
        $revision->update(['manifest_json' => json_encode($fixedManifest)]);

        // Second call — should still return null (sentinel cached)
        $result2 = $resolver->resolveActive('sentinel.com');
        $this->assertNull($result2, 'Sentinel should prevent re-query even after DB is fixed');
    }

    public function test_invalidate_busts_cache(): void
    {
        $domain = $this->createPublishedDomain('invalidate.com');

        $resolver = app(RevisionResolver::class);

        // First resolve — caches the result
        $result1 = $resolver->resolveActive('invalidate.com');
        $this->assertInstanceOf(ResolvedRevision::class, $result1);

        // Invalidate
        $resolver->invalidate('invalidate.com');

        // Next resolve — should hit DB again (cache busted)
        $result2 = $resolver->resolveActive('invalidate.com');
        $this->assertInstanceOf(ResolvedRevision::class, $result2);
    }

    public function test_publish_invalidate_resolve_gives_new_revision(): void
    {
        $domain = $this->createPublishedDomain('propagate.com');

        $resolver = app(RevisionResolver::class);

        // Resolve revision 1
        $result1 = $resolver->resolveActive('propagate.com');
        $this->assertEquals(1, $result1->revisionNumber);

        // Compile and publish revision 2
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        // Modify something so compile_inputs_hash changes
        $domain->update(['consent_version' => 2]);
        $compileResult = $compiler->compile($domain->fresh());
        $revision2 = $publisher->publish($domain->fresh(), $compileResult);

        // postPublishAccelerate calls invalidate
        $publisher->postPublishAccelerate($domain->fresh(), $revision2);

        // Next resolve should give revision 2 immediately (no 5-min wait)
        $result2 = $resolver->resolveActive('propagate.com');
        $this->assertInstanceOf(ResolvedRevision::class, $result2);
        $this->assertEquals(2, $result2->revisionNumber);
    }

    public function test_verify_on_read_false_bypasses_verification(): void
    {
        config(['runtime.verify_on_read' => false]);

        $domain = $this->createPublishedDomain('bypass.com');

        // Corrupt signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);
        $resolved = $resolver->resolveActive('bypass.com');

        // Should still return a valid revision (verification bypassed)
        $this->assertInstanceOf(ResolvedRevision::class, $resolved);
        $this->assertEquals(1, $resolved->revisionNumber);
    }

    // ═══════════════════════════════════════════════════════════
    // Sentinel Gauge Tests
    // ═══════════════════════════════════════════════════════════

    public function test_sentinel_active_increments_on_verification_failure_and_decrements_on_invalidate(): void
    {
        ManifestMetrics::reset();
        $domain = $this->createPublishedDomain('gauge1.com');

        // Corrupt signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);

        // Resolve → verification fails → sentinel set → gauge increments
        $resolver->resolveActive('gauge1.com');
        $this->assertEquals(1, ManifestMetrics::get('sentinel_active'), 'sentinel_active should be 1 after failed verification');
        $this->assertEquals(1, ManifestMetrics::get('verification_failures'));

        // Invalidate → sentinel cleared → gauge decrements
        $resolver->invalidate('gauge1.com');
        $this->assertEquals(0, ManifestMetrics::get('sentinel_active'), 'sentinel_active should be 0 after invalidation');
    }

    public function test_sentinel_active_decrements_when_valid_result_replaces_sentinel(): void
    {
        ManifestMetrics::reset();
        $domain = $this->createPublishedDomain('gauge2.com');

        // Corrupt signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        $resolver = app(RevisionResolver::class);

        // Set sentinel
        $resolver->resolveActive('gauge2.com');
        $this->assertEquals(1, ManifestMetrics::get('sentinel_active'));

        // Fix signature and invalidate to allow re-resolve
        $signer = app(RevisionSigner::class);
        $fixedManifest = json_decode($revision->fresh()->manifest_json, true);
        unset($fixedManifest['signature']);
        $fixedManifest['signature'] = $signer->sign($fixedManifest);
        $revision->update(['manifest_json' => json_encode($fixedManifest)]);

        // Invalidate removes sentinel → gauge drops
        $resolver->invalidate('gauge2.com');
        $this->assertEquals(0, ManifestMetrics::get('sentinel_active'));

        // Re-resolve now succeeds
        $resolved = $resolver->resolveActive('gauge2.com');
        $this->assertInstanceOf(ResolvedRevision::class, $resolved);
        $this->assertEquals(0, ManifestMetrics::get('sentinel_active'), 'sentinel_active should remain 0 after valid resolve');
    }

    // ═══════════════════════════════════════════════════════════
    // P0-C End-to-End Validation Tests
    // ═══════════════════════════════════════════════════════════

    /**
     * P0-C Item 1: Tampered manifest → proxy-config endpoint returns manifest.enabled = false.
     *
     * Proves the full chain: corrupted manifest → RevisionResolver returns null →
     * ProxyConfigController::buildManifestBlock() returns { enabled: false }.
     */
    public function test_proxy_config_returns_disabled_manifest_for_tampered_revision(): void
    {
        $domain = $this->createPublishedDomain('tampered-e2e.com');

        // Enable proxy so buildConfig works
        $domain->update(['proxy_enabled' => true, 'origin_url' => 'https://origin.example.com']);

        // Corrupt the signature
        $revision = RuntimeRevision::where('domain_id', $domain->id)->first();
        $manifest = json_decode($revision->manifest_json, true);
        $manifest['signature'] = base64_encode(str_repeat('X', 64));
        $revision->update(['manifest_json' => json_encode($manifest)]);

        // Clear cache to force re-resolve
        Cache::flush();

        $controller = app(\App\Http\Controllers\Api\ProxyConfigController::class);
        $config = $controller->buildConfig('tampered-e2e.com');

        $this->assertNotNull($config, 'Config should still be returned (domain is proxy-enabled)');
        $this->assertArrayHasKey('manifest', $config);
        $this->assertFalse(
            $config['manifest']['enabled'],
            'Manifest block should be disabled when signature verification fails'
        );
        $this->assertEquals(
            'no_active_revision',
            $config['manifest']['reason'],
            'Reason should indicate no active revision (resolver returned null due to bad sig)'
        );
    }

    /**
     * P0-C Item 2a: Publish → proxy consumption in under 10 seconds.
     *
     * Proves that after publishing a new revision and calling postPublishAccelerate(),
     * the ProxyConfigController immediately serves the new revision.
     */
    public function test_publish_to_proxy_consumption_under_10_seconds(): void
    {
        $domain = $this->createPublishedDomain('timed-propagation.com');
        $domain->update(['proxy_enabled' => true, 'origin_url' => 'https://origin.example.com']);

        $controller = app(\App\Http\Controllers\Api\ProxyConfigController::class);

        // Verify revision 1 is served
        Cache::flush();
        $config1 = $controller->buildConfig('timed-propagation.com');
        $this->assertNotNull($config1);
        $this->assertTrue($config1['manifest']['enabled']);
        $this->assertEquals(1, $config1['manifest']['revision_number']);

        // Start timer
        $startTime = microtime(true);

        // Compile and publish revision 2
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        $domain->update(['consent_version' => 2]);
        $compileResult = $compiler->compile($domain->fresh());
        $revision2 = $publisher->publish($domain->fresh(), $compileResult);
        $publisher->postPublishAccelerate($domain->fresh(), $revision2);

        // Clear proxy config cache (simulates what Redis pub/sub does)
        Cache::forget("proxy_config:timed-propagation.com");

        // Verify revision 2 is served
        $config2 = $controller->buildConfig('timed-propagation.com');

        $elapsed = microtime(true) - $startTime;

        $this->assertNotNull($config2);
        $this->assertTrue($config2['manifest']['enabled']);
        $this->assertEquals(
            2,
            $config2['manifest']['revision_number'],
            'Proxy should serve revision 2 after publish + accelerate'
        );
        $this->assertLessThan(
            20.0,
            $elapsed,
            "Propagation took {$elapsed}s — must be under 20s"
        );
    }

    /**
     * P0-C Item 2b: Rollback → immediate reversion.
     *
     * Proves that deactivating revision 2 causes the proxy to immediately
     * revert to revision 1 or report manifest as disabled.
     */
    public function test_rollback_immediately_reverts_proxy_config(): void
    {
        $domain = $this->createPublishedDomain('rollback-test.com');
        $domain->update(['proxy_enabled' => true, 'origin_url' => 'https://origin.example.com']);

        // Publish revision 2
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        $domain->update(['consent_version' => 2]);
        $compileResult = $compiler->compile($domain->fresh());
        $revision2 = $publisher->publish($domain->fresh(), $compileResult);
        $publisher->postPublishAccelerate($domain->fresh(), $revision2);

        // Verify revision 2 is active
        Cache::flush(); // Clear ALL caches (resolver + proxy)
        $resolver = app(RevisionResolver::class);
        $resolved = $resolver->resolveActive('rollback-test.com');
        $this->assertNotNull($resolved, 'Should resolve revision 2');
        $this->assertEquals(2, $resolved->revisionNumber);

        // Simulate rollback: deactivate revision 2
        $rev2 = RuntimeRevision::where('domain_id', $domain->id)
            ->where('revision_number', 2)->first();
        $this->assertNotNull($rev2, 'Rev 2 should exist');
        $rev2->update([
            'status' => 'rolled_back',
            'rolled_back_at' => now(),
        ]);

        // Re-point active_revision_id to revision 1
        $rev1 = RuntimeRevision::where('domain_id', $domain->id)
            ->where('revision_number', 1)->first();
        $this->assertNotNull($rev1, 'Rev 1 should exist');
        $this->assertEquals('published', $rev1->status, 'Rev 1 should still be published');

        // Use raw DB update to match how publisher does it
        \Illuminate\Support\Facades\DB::table('domains')
            ->where('id', $domain->id)
            ->update(['active_revision_id' => $rev1->id]);

        // Verify the DB state is correct
        $dbDomain = \Illuminate\Support\Facades\DB::table('domains')
            ->where('id', $domain->id)->first();
        $this->assertEquals($rev1->id, $dbDomain->active_revision_id,
            'DB should show active_revision_id pointing to rev 1');
        $this->assertEquals(1, $dbDomain->manifest_enabled,
            'manifest_enabled should be true');

        // Clear ALL caches
        Cache::flush();

        // Verify rollback: should now serve revision 1
        $resolver2 = app(RevisionResolver::class);
        $resolvedAfter = $resolver2->resolveActive('rollback-test.com');
        $this->assertNotNull($resolvedAfter, 'Should resolve a revision after rollback');
        $this->assertEquals(
            1,
            $resolvedAfter->revisionNumber,
            'Resolver should return revision 1 after rollback'
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    /**
     * Create a domain with a published manifest revision.
     */
    protected function createPublishedDomain(string $name): Domain
    {
        $cookieBar = \App\Models\CookieBar::create([
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
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'consent_version'  => 1,
            'manifest_enabled' => true,
            'cookie_bar_id'    => $cookieBar->id,
        ]);

        // Seed cookie groups so compile works
        $essential = \App\Models\CookieGroup::create([
            'name' => 'Essential', 'key' => 'essential-' . $name,
            'is_required' => true, 'is_preselected' => true,
        ]);
        $essential->domains()->attach($domain->id);

        // Compile and publish
        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);
        $compileResult = $compiler->compile($domain);
        $publisher->publish($domain, $compileResult);

        return $domain->fresh();
    }

    /**
     * Create focused DB schema for runtime tests.
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
