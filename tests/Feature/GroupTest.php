<?php

namespace Tests\Feature;

use App\Models\ContentBlocker;
use App\Models\CookieBar;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_assign_all_resources_to_a_master_group(): void
    {
        $group = Group::create([
            'name' => 'Marketing Department',
            'description' => 'A group for all marketing domains and settings',
        ]);

        $domain = Domain::create([
            'name' => 'marketing.test',
            'site_id' => '11112222333344445555666677778888',
            'is_active' => true,
            'group_id' => $group->id,
        ]);

        $cookieBar = CookieBar::create([
            'name' => 'Marketing Banner',
            'group_id' => $group->id,
            'theme_settings' => [],
            'translations' => [],
        ]);

        $cookieGroup = CookieGroup::create([
            'name' => 'Essential',
            'key' => 'essential',
            'is_required' => true,
            'group_id' => $group->id,
        ]);

        $provider = \App\Models\Provider::create([
            'name' => 'Google',
        ]);

        $service = Service::create([
            'name' => 'Google Analytics',
            'provider_id' => $provider->id,
            'key' => 'ga',
            'cookie_group_id' => $cookieGroup->id,
            'group_id' => $group->id,
            'is_active' => true,
        ]);

        $contentBlocker = ContentBlocker::create([
            'name' => 'YouTube Blocker',
            'key' => 'youtube',
            'service_id' => $service->id,
            'group_id' => $group->id,
            'domain_id' => $domain->id,
            'is_active' => true,
        ]);

        $group->refresh();

        // Assert relationships
        $this->assertCount(1, $group->domains);
        $this->assertEquals('marketing.test', $group->domains->first()->name);

        $this->assertCount(1, $group->cookieBars);
        $this->assertEquals('Marketing Banner', $group->cookieBars->first()->name);

        // DomainObserver may attach default cookie groups; assert our tenant group exists
        $essential = $group->cookieGroups->firstWhere('key', 'essential');
        $this->assertNotNull($essential);
        $this->assertEquals('Essential', $essential->name);

        $this->assertCount(1, $group->services);
        $this->assertEquals('Google Analytics', $group->services->first()->name);

        $this->assertCount(1, $group->contentBlockers);
        $this->assertEquals('YouTube Blocker', $group->contentBlockers->first()->name);
    }
}
