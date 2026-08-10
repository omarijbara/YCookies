<?php

namespace Tests\Feature\Console;

use App\Models\ConsentLog;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PurgeConsentLogsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_purges_consent_logs_older_than_group_retention_days()
    {
        // 1. Setup a group with retention of 30 days
        $group = Group::factory()->create([
            'consent_retention_days' => 30,
        ]);

        $domain = Domain::factory()->create([
            'group_id' => $group->id,
        ]);

        // 2. Create logs that should be purged (31 days old)
        ConsentLog::factory()->count(5)->create([
            'domain_id' => $domain->id,
            'created_at' => now()->subDays(31),
        ]);

        // 3. Create logs that should NOT be purged (29 days old)
        ConsentLog::factory()->count(3)->create([
            'domain_id' => $domain->id,
            'created_at' => now()->subDays(29),
        ]);

        // Ensure we have 8 logs total
        $this->assertEquals(8, ConsentLog::count());

        // 4. Run the command
        $this->artisan('ycookies:purge-consent-logs')
            ->assertSuccessful()
            ->expectsOutput('Starting Consent Logs Purge...')
            ->expectsOutput('Purge complete. Total deleted: 5');

        // 5. Assert only the newer logs remain
        $this->assertEquals(3, ConsentLog::count());
    }

    #[Test]
    public function it_uses_default_retention_if_group_setting_is_null()
    {
        // 1. Setup a group (retention defaults to 365)
        $group = Group::factory()->create();

        $domain = Domain::factory()->create([
            'group_id' => $group->id,
        ]);

        // 2. Create logs that should be purged (366 days old)
        ConsentLog::factory()->count(2)->create([
            'domain_id' => $domain->id,
            'created_at' => now()->subDays(366),
        ]);

        // 3. Create logs that should NOT be purged (364 days old)
        ConsentLog::factory()->count(4)->create([
            'domain_id' => $domain->id,
            'created_at' => now()->subDays(364),
        ]);

        $this->assertEquals(6, ConsentLog::count());

        // 4. Run the command
        $this->artisan('ycookies:purge-consent-logs')
            ->assertSuccessful()
            ->expectsOutput('Starting Consent Logs Purge...')
            ->expectsOutput('Purge complete. Total deleted: 2');

        $this->assertEquals(4, ConsentLog::count());
    }

    #[Test]
    public function it_purges_only_the_given_group_when_group_option_is_set()
    {
        $groupA = Group::factory()->create(['consent_retention_days' => 30]);
        $groupB = Group::factory()->create(['consent_retention_days' => 30]);

        $domainA = Domain::factory()->create(['group_id' => $groupA->id]);
        $domainB = Domain::factory()->create(['group_id' => $groupB->id]);

        ConsentLog::factory()->count(2)->create([
            'domain_id' => $domainA->id,
            'created_at' => now()->subDays(31),
        ]);
        ConsentLog::factory()->count(3)->create([
            'domain_id' => $domainB->id,
            'created_at' => now()->subDays(31),
        ]);

        $this->assertEquals(5, ConsentLog::count());

        $this->artisan('ycookies:purge-consent-logs', ['--group' => (string) $groupA->id])
            ->assertSuccessful();

        $this->assertEquals(3, ConsentLog::count());
        $this->assertEquals(0, ConsentLog::where('domain_id', $domainA->id)->count());
        $this->assertEquals(3, ConsentLog::where('domain_id', $domainB->id)->count());
    }
}
