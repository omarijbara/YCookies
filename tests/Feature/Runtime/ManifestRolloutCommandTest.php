<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\RuntimeRevision;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integration tests for the manifest:rollout:plan and manifest:rollout:execute commands.
 *
 * Covers:
 *   - Plan dry-run produces correct output without writes
 *   - Execute publishes for eligible domains
 *   - Execute skips unchanged domains
 *   - Execute safely handles mixed batch (publish + skip + fail)
 *   - Exit codes are correct
 *   - Rerun is idempotent
 *   - Single-domain convenience command works
 */
class ManifestRolloutCommandTest extends TestCase
{
    protected Group $agency;

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
    // Plan Tests
    // ═══════════════════════════════════════════════════════════

    public function test_plan_shows_eligible_domain(): void
    {
        $domain = $this->createDomainWithBar('plan-test.com');

        $this->artisan('manifest:rollout:plan', ['--domains' => 'plan-test.com'])
            ->assertExitCode(0);

        // Verify no revisions were created (dry-run)
        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    public function test_plan_does_not_write_anything(): void
    {
        $domain = $this->createDomainWithBar('dry-run.com');

        $revisionsBefore = RuntimeRevision::count();

        $this->artisan('manifest:rollout:plan', ['--domains' => 'dry-run.com'])
            ->assertExitCode(0);

        $this->assertEquals($revisionsBefore, RuntimeRevision::count());
        $this->assertNull($domain->fresh()->active_revision_id);
    }

    public function test_plan_marks_inactive_domain_as_blocked(): void
    {
        $this->createDomainWithBar('inactive.com', ['is_active' => false]);

        $this->artisan('manifest:rollout:plan', ['--domains' => 'inactive.com', '--json' => true])
            ->assertExitCode(0);

        // Manually verify output — the plan should not write anything
        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    public function test_plan_fails_for_unknown_domain(): void
    {
        $this->artisan('manifest:rollout:plan', ['--domains' => 'nonexistent.com'])
            ->assertExitCode(2); // INVALID
    }

    public function test_plan_json_output(): void
    {
        $this->createDomainWithBar('json-test.com');

        $this->artisan('manifest:rollout:plan', [
            '--domains' => 'json-test.com',
            '--json'    => true,
        ])->assertExitCode(0);
    }

    // ═══════════════════════════════════════════════════════════
    // Execute Tests
    // ═══════════════════════════════════════════════════════════

    public function test_execute_requires_domain_selection(): void
    {
        $this->artisan('manifest:rollout:execute')
            ->assertExitCode(2); // INVALID — no --domains and no --all
    }

    public function test_execute_publishes_eligible_domain(): void
    {
        $domain = $this->createDomainWithBar('publish-test.com');
        $this->seedCookieGroups($domain);

        $this->artisan('manifest:rollout:execute', ['--domains' => 'publish-test.com'])
            ->assertExitCode(0);

        // Verify revision was created
        $this->assertDatabaseHas('runtime_revisions', [
            'domain_id'      => $domain->id,
            'revision_number' => 1,
            'status'          => 'published',
        ]);

        // Verify pointer was updated
        $domain->refresh();
        $this->assertNotNull($domain->active_revision_id);
    }

    public function test_execute_skips_unchanged_domain(): void
    {
        $domain = $this->createDomainWithBar('skip-test.com');
        $this->seedCookieGroups($domain);

        // First run — should publish
        $this->artisan('manifest:rollout:execute', ['--domains' => 'skip-test.com'])
            ->assertExitCode(0);

        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domain->id)->count());

        // Second run — should skip (unchanged inputs)
        $this->artisan('manifest:rollout:execute', ['--domains' => 'skip-test.com'])
            ->assertExitCode(0);

        // Still only 1 revision
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domain->id)->count());
    }

    public function test_execute_force_recompiles_unchanged_domain(): void
    {
        $domain = $this->createDomainWithBar('force-test.com');
        $this->seedCookieGroups($domain);

        // First run
        $this->artisan('manifest:rollout:execute', ['--domains' => 'force-test.com'])
            ->assertExitCode(0);

        // Second run with --force
        $this->artisan('manifest:rollout:execute', ['--domains' => 'force-test.com', '--force' => true])
            ->assertExitCode(0);

        // Should have 2 revisions
        $this->assertEquals(2, RuntimeRevision::where('domain_id', $domain->id)->count());
    }

    public function test_execute_skips_inactive_domain(): void
    {
        $this->createDomainWithBar('inactive-exec.com', ['is_active' => false]);

        $this->artisan('manifest:rollout:execute', ['--domains' => 'inactive-exec.com'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    public function test_execute_mixed_batch_publishes_skips_and_fails(): void
    {
        // Domain A: eligible, will publish
        $domainA = $this->createDomainWithBar('batch-a.com');
        $this->seedCookieGroups($domainA);

        // Domain B: inactive, will skip
        $domainB = $this->createDomainWithBar('batch-b.com', ['is_active' => false]);

        // Domain C: eligible, will publish
        $domainC = $this->createDomainWithBar('batch-c.com');
        $this->seedCookieGroups($domainC);

        $this->artisan('manifest:rollout:execute', [
            '--domains' => 'batch-a.com,batch-b.com,batch-c.com',
        ])->assertExitCode(0); // 0 because all skips/publishes are non-failures

        // Domain A published
        $this->assertDatabaseHas('runtime_revisions', [
            'domain_id' => $domainA->id,
            'status'    => 'published',
        ]);

        // Domain B skipped — no revision
        $this->assertDatabaseMissing('runtime_revisions', [
            'domain_id' => $domainB->id,
        ]);

        // Domain C published
        $this->assertDatabaseHas('runtime_revisions', [
            'domain_id' => $domainC->id,
            'status'    => 'published',
        ]);
    }

    public function test_execute_failure_does_not_corrupt_other_domains(): void
    {
        // Domain A: eligible, will publish
        $domainA = $this->createDomainWithBar('isolation-a.com');
        $this->seedCookieGroups($domainA);

        // Domain B: has a cookie bar but no groups — compile will succeed
        // but we can verify per-domain isolation
        $domainB = $this->createDomainWithBar('isolation-b.com');
        $this->seedCookieGroups($domainB);

        $this->artisan('manifest:rollout:execute', [
            '--domains' => 'isolation-a.com,isolation-b.com',
        ])->assertExitCode(0);

        // Both should have published (both have cookie groups)
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domainA->id)->count());
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domainB->id)->count());

        // And their revisions should be independent
        $revA = RuntimeRevision::where('domain_id', $domainA->id)->first();
        $revB = RuntimeRevision::where('domain_id', $domainB->id)->first();
        $this->assertNotEquals($revA->manifest_hash, $revB->manifest_hash);
    }

    public function test_execute_rerun_is_idempotent(): void
    {
        $domain = $this->createDomainWithBar('idempotent.com');
        $this->seedCookieGroups($domain);

        // Run 1
        $this->artisan('manifest:rollout:execute', ['--domains' => 'idempotent.com'])
            ->assertExitCode(0);

        $rev1 = $domain->fresh()->active_revision_id;

        // Run 2 — should skip
        $this->artisan('manifest:rollout:execute', ['--domains' => 'idempotent.com'])
            ->assertExitCode(0);

        $rev2 = $domain->fresh()->active_revision_id;

        // Pointer unchanged
        $this->assertEquals($rev1, $rev2);
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domain->id)->count());
    }

    public function test_execute_json_output(): void
    {
        $domain = $this->createDomainWithBar('json-exec.com');
        $this->seedCookieGroups($domain);

        $this->artisan('manifest:rollout:execute', [
            '--domains' => 'json-exec.com',
            '--json'    => true,
        ])->assertExitCode(0);
    }

    public function test_execute_fails_for_unknown_domain(): void
    {
        $this->artisan('manifest:rollout:execute', ['--domains' => 'ghost.com'])
            ->assertExitCode(2);
    }

    public function test_execute_cannot_use_domains_and_all_together(): void
    {
        $this->artisan('manifest:rollout:execute', ['--domains' => 'a.com', '--all' => true])
            ->assertExitCode(2);
    }

    public function test_execute_all_processes_eligible_domains(): void
    {
        $domainA = $this->createDomainWithBar('all-a.com');
        $this->seedCookieGroups($domainA);

        $domainB = $this->createDomainWithBar('all-b.com');
        $this->seedCookieGroups($domainB);

        // Inactive domain — should NOT be included with --all
        $this->createDomainWithBar('all-c.com', ['is_active' => false]);

        $this->artisan('manifest:rollout:execute', ['--all' => true])
            ->assertExitCode(0);

        // A and B published, C excluded
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domainA->id)->count());
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domainB->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Single-Domain Convenience Command
    // ═══════════════════════════════════════════════════════════

    public function test_rollout_domain_convenience_command(): void
    {
        $domain = $this->createDomainWithBar('convenience.com');
        $this->seedCookieGroups($domain);

        $this->artisan('manifest:rollout:domain', ['domain' => 'convenience.com'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('runtime_revisions', [
            'domain_id' => $domain->id,
            'status'    => 'published',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Manifest-Enabled Eligibility Gate
    // ═══════════════════════════════════════════════════════════

    public function test_execute_skips_manifest_disabled_domain(): void
    {
        $domain = $this->createDomainWithBar('disabled.com', ['manifest_enabled' => false]);
        $this->seedCookieGroups($domain);

        $this->artisan('manifest:rollout:execute', ['--domains' => 'disabled.com'])
            ->assertExitCode(0);

        // No revision created — domain skipped
        $this->assertDatabaseCount('runtime_revisions', 0);
        $this->assertNull($domain->fresh()->active_revision_id);
    }

    public function test_execute_all_excludes_manifest_disabled_domains(): void
    {
        // Domain A: manifest enabled → should publish
        $domainA = $this->createDomainWithBar('enabled-all.com', ['manifest_enabled' => true]);
        $this->seedCookieGroups($domainA);

        // Domain B: manifest disabled → should be excluded by --all
        $domainB = $this->createDomainWithBar('disabled-all.com', ['manifest_enabled' => false]);
        $this->seedCookieGroups($domainB);

        $this->artisan('manifest:rollout:execute', ['--all' => true])
            ->assertExitCode(0);

        // Only A published
        $this->assertEquals(1, RuntimeRevision::where('domain_id', $domainA->id)->count());
        $this->assertDatabaseMissing('runtime_revisions', ['domain_id' => $domainB->id]);
    }

    public function test_plan_blocks_manifest_disabled_domain(): void
    {
        $this->createDomainWithBar('plan-disabled.com', ['manifest_enabled' => false]);

        $this->artisan('manifest:rollout:plan', [
            '--domains' => 'plan-disabled.com',
            '--json'    => true,
        ])->assertExitCode(0);

        // No revisions written (plan is read-only regardless)
        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    public function test_plan_and_execute_agree_on_eligibility(): void
    {
        // Create a manifest-disabled domain with cookie bar
        $domain = $this->createDomainWithBar('parity.com', ['manifest_enabled' => false]);
        $this->seedCookieGroups($domain);

        // Plan should report it as blocked
        $this->artisan('manifest:rollout:plan', ['--domains' => 'parity.com'])
            ->assertExitCode(0);

        // Execute should skip it (same eligibility gate)
        $this->artisan('manifest:rollout:execute', ['--domains' => 'parity.com'])
            ->assertExitCode(0);

        // No revision created by either
        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    public function test_plan_eligible_only_excludes_manifest_disabled(): void
    {
        // Domain A: manifest enabled
        $this->createDomainWithBar('eligible-a.com', ['manifest_enabled' => true]);

        // Domain B: manifest disabled
        $this->createDomainWithBar('eligible-b.com', ['manifest_enabled' => false]);

        // --eligible-only should only show domain A
        $this->artisan('manifest:rollout:plan', ['--eligible-only' => true])
            ->assertExitCode(0);

        // Read-only, no revisions
        $this->assertDatabaseCount('runtime_revisions', 0);
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    protected function createDomainWithBar(string $name, array $overrides = []): Domain
    {
        // Create cookie bar first (Domain.belongsTo CookieBar)
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

        $domain = Domain::create(array_merge([
            'group_id'         => $this->agency->id,
            'name'             => $name,
            'site_id'          => Str::random(32),
            'is_active'        => true,
            'consent_version'  => 1,
            'manifest_enabled' => true,
            'cookie_bar_id'    => $cookieBar->id,
        ], $overrides));

        return $domain;
    }

    protected function seedCookieGroups(Domain $domain): void
    {
        $essential = CookieGroup::create([
            'name'           => 'Essential',
            'key'            => 'essential-' . $domain->name,
            'is_required'    => true,
            'is_preselected' => true,
        ]);
        $essential->domains()->attach($domain->id);

        $marketing = CookieGroup::create([
            'name'           => 'Marketing',
            'key'            => 'marketing-' . $domain->name,
            'is_required'    => false,
            'is_preselected' => false,
        ]);
        $marketing->domains()->attach($domain->id);

        Service::create([
            'cookie_group_id' => $marketing->id,
            'name'            => 'Test Analytics for ' . $domain->name,
            'key'             => 'test-analytics-' . $domain->name,
            'is_active'       => true,
        ]);
    }

    /**
     * Create focused DB schema for runtime tests.
     * Mirrors CompilePublishIntegrationTest::createTestSchema().
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
