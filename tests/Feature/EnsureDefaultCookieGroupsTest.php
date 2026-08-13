<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Group;
use App\Services\EnsureDefaultCookieGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureDefaultCookieGroupsTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function artisan_command_backfills_groups_for_existing_tenant(): void
    {
        $expected = count(EnsureDefaultCookieGroups::definitions());
        $group = Group::factory()->create();

        $this->artisan('ycookies:ensure-cookie-groups', ['group' => (string) $group->id])
            ->assertSuccessful();

        $this->assertSame($expected, $group->fresh()->cookieGroups()->count());

        $domain = Domain::factory()->create(['group_id' => $group->id]);
        $this->assertSame($expected, $domain->cookieGroups()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function attach_all_to_domain_is_idempotent(): void
    {
        $group = Group::factory()->create();
        $domain = Domain::factory()->create(['group_id' => $group->id]);

        EnsureDefaultCookieGroups::attachAllToDomain($domain);
        EnsureDefaultCookieGroups::attachAllToDomain($domain);

        $this->assertSame(
            count(EnsureDefaultCookieGroups::definitions()),
            $domain->fresh()->cookieGroups()->count()
        );
    }
}
