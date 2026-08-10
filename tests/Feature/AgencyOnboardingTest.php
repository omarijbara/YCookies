<?php

namespace Tests\Feature;

use App\Filament\Pages\Tenancy\RegisterGroup;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AgencyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(function ($user, $ability) {
            return true;
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_register_a_group_with_domain_and_prepopulated_configs()
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withoutExceptionHandling();

        // RegisterTenant components require an active panel context
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(RegisterGroup::class)
            ->fillForm([
                'name' => 'Agency Alpha',
                'domain_name' => 'client1.test',
                'origin_url' => 'https://client1.test',
                'prepopulate_config' => true,
                'contact_email' => 'admin@agencyalpha.test',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        // Assert Group created
        $this->assertDatabaseHas('groups', [
            'name' => 'Agency Alpha',
        ]);

        $group = Group::where('name', 'Agency Alpha')->first();

        // Assert User is attached as owner
        $this->assertTrue($user->groups->contains($group->id));

        // Assert Domain created
        $this->assertDatabaseHas('domains', [
            'name' => 'client1.test',
            'origin_url' => 'https://client1.test',
            'report_email' => 'admin@agencyalpha.test',
            'group_id' => $group->id,
            'scheduler_enabled' => 1,
            'proxy_enabled' => 1,
        ]);

        $domain = Domain::where('name', 'client1.test')->first();

        // Assert Default CookieBar created
        $this->assertDatabaseHas('cookie_bars', [
            'name' => 'Default Cookie Banner',
            'group_id' => $group->id,
        ]);

        // Assert default system cookie groups (see EnsureDefaultCookieGroups)
        foreach (['essential', 'first_party', 'analytics', 'statistics', 'marketing', 'external_media'] as $key) {
            $this->assertDatabaseHas('cookie_groups', [
                'group_id' => $group->id,
                'key' => $key,
            ]);
        }

        $domain->refresh();
        $this->assertEquals(6, $domain->cookieGroups()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_register_a_group_without_prepopulation()
    {
        Queue::fake();

        $user = User::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(RegisterGroup::class)
            ->fillForm([
                'name' => 'Agency Beta',
                'domain_name' => 'client2.test',
                'origin_url' => 'https://client2.test',
                'prepopulate_config' => false,
                'contact_email' => 'admin@agencybeta.test',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $group = Group::where('name', 'Agency Beta')->first();

        $this->assertDatabaseHas('domains', [
            'name' => 'client2.test',
            'group_id' => $group->id,
        ]);

        $this->assertDatabaseMissing('cookie_bars', [
            'group_id' => $group->id,
        ]);

        // No banner prepopulation, but default cookie groups are still provisioned when the domain is created
        $this->assertEquals(6, CookieGroup::where('group_id', $group->id)->count());
        $domain = Domain::where('name', 'client2.test')->first();
        $this->assertEquals(6, $domain->cookieGroups()->count());
    }
}
