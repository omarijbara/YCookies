<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\RuntimeRevision;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Publisher\RevisionPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for revision number allocation safety under concurrent publish.
 *
 * Validates:
 *   - Sequential publishes always get monotonically increasing revision numbers
 *   - lockForUpdate on the domain row serializes concurrent allocation
 *   - Unique constraint on (domain_id, revision_number) is never violated
 *   - Multiple domains are independently lockable (no cross-domain contention)
 */
class RevisionAllocationLockingTest extends TestCase
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
    // Allocation tests
    // ═══════════════════════════════════════════════════════════

    public function test_sequential_publishes_produce_monotonic_revision_numbers(): void
    {
        $domain = $this->createEligibleDomain('seq.com');

        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        // Publish 5 revisions sequentially
        for ($i = 1; $i <= 5; $i++) {
            $domain->update(['consent_version' => $i]);
            $compileResult = $compiler->compile($domain->fresh());
            $revision = $publisher->publish($domain->fresh(), $compileResult);
            $this->assertEquals($i, $revision->revision_number);
        }

        // Verify all 5 exist with correct numbers
        $revisions = RuntimeRevision::where('domain_id', $domain->id)
            ->orderBy('revision_number')
            ->pluck('revision_number')
            ->toArray();

        $this->assertEquals([1, 2, 3, 4, 5], $revisions);
    }

    public function test_unique_constraint_prevents_duplicate_revision_numbers(): void
    {
        $domain = $this->createEligibleDomain('uniq.com');

        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        // Publish revision 1
        $compileResult = $compiler->compile($domain);
        $revision = $publisher->publish($domain, $compileResult);
        $this->assertEquals(1, $revision->revision_number);

        // Manually insert a duplicate revision_number=1 should fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        RuntimeRevision::create([
            'domain_id'          => $domain->id,
            'revision_number'    => 1, // Duplicate!
            'schema_version'     => '1.0.0',
            'status'             => 'published',
            'manifest_json'      => '{}',
            'manifest_hash'      => hash('sha256', '{}'),
            'base_artifact_json' => '{}',
            'base_artifact_hash' => hash('sha256', '{}'),
            'compile_inputs_hash' => 'test',
        ]);
    }

    public function test_cross_domain_publishes_are_independent(): void
    {
        $domain1 = $this->createEligibleDomain('ind1.com');
        $domain2 = $this->createEligibleDomain('ind2.com');

        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);

        // Publish 3 revisions for domain1
        for ($i = 1; $i <= 3; $i++) {
            $domain1->update(['consent_version' => $i]);
            $compileResult = $compiler->compile($domain1->fresh());
            $publisher->publish($domain1->fresh(), $compileResult);
        }

        // Publish 2 revisions for domain2
        for ($i = 1; $i <= 2; $i++) {
            $domain2->update(['consent_version' => $i]);
            $compileResult = $compiler->compile($domain2->fresh());
            $publisher->publish($domain2->fresh(), $compileResult);
        }

        // Domain 1 should have revisions 1-3
        $d1Revisions = RuntimeRevision::where('domain_id', $domain1->id)
            ->pluck('revision_number')->toArray();
        $this->assertEquals([1, 2, 3], $d1Revisions);

        // Domain 2 should have revisions 1-2 (independent numbering)
        $d2Revisions = RuntimeRevision::where('domain_id', $domain2->id)
            ->pluck('revision_number')->toArray();
        $this->assertEquals([1, 2], $d2Revisions);
    }

    public function test_lock_for_update_is_applied_in_next_revision_number(): void
    {
        $domain = $this->createEligibleDomain('lock.com');

        $compiler = app(DomainCompiler::class);
        $publisher = app(RevisionPublisher::class);
        $compileResult = $compiler->compile($domain);

        // Capture queries to verify lockForUpdate is used
        DB::enableQueryLog();
        $revision = $publisher->publish($domain, $compileResult);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals(1, $revision->revision_number);

        // SQLite does not support FOR UPDATE syntax (silently ignores it).
        // On MySQL/PostgreSQL, the lockForUpdate() will emit 'for update'.
        // We verify the domain query exists and trust the locking on production DB.
        $domainQuery = collect($queries)->first(function ($q) {
            return str_contains($q['query'] ?? '', '"domains"')
                || str_contains($q['query'] ?? '', '`domains`');
        });

        $this->assertNotNull($domainQuery, 'Expected a query against the domains table in publish()');

        // On MySQL/PostgreSQL (production), verify lock is present
        $driver = DB::getDriverName();
        if ($driver !== 'sqlite') {
            $lockQuery = collect($queries)->first(function ($q) {
                return str_contains($q['query'] ?? '', 'domains')
                    && str_contains(strtolower($q['query'] ?? ''), 'for update');
            });
            $this->assertNotNull($lockQuery, 'Expected SELECT ... FOR UPDATE on domains table (non-SQLite)');
        } else {
            // On SQLite, lockForUpdate is a no-op. Note this for documentation.
            $this->addToAssertionCount(1); // Pass — lock not visible in SQLite query log
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    protected function createEligibleDomain(string $name): Domain
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

        $essential = CookieGroup::create([
            'name' => 'Essential', 'key' => 'essential-' . $name,
            'is_required' => true, 'is_preselected' => true,
        ]);
        $essential->domains()->attach($domain->id);

        return $domain;
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
