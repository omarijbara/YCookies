<?php

namespace Tests\Feature;

use App\Http\Middleware\ContentBlockerMiddleware;
use App\Models\ContentBlocker;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentBlockerMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected Domain $domain;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $group = Group::create(['name' => 'Test Agency']);
        $this->domain = Domain::create([
            'group_id' => $group->id,
            'name' => 'localhost',
            'site_id' => Str::random(32),
            'is_active' => true,
        ]);

        $cookieGroup = CookieGroup::create([
            'name' => 'Marketing',
            'key' => 'marketing',
            'is_required' => false,
        ]);
        $cookieGroup->domains()->attach($this->domain->id);

        $this->service = Service::create([
            'cookie_group_id' => $cookieGroup->id,
            'name' => 'YouTube',
            'key' => 'youtube',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    protected function runMiddleware(string $htmlContent, string $host = 'localhost'): string
    {
        $request = Request::create('http://'.$host.'/test-page', 'GET');
        $request->headers->set('Host', $host);

        $middleware = new ContentBlockerMiddleware;

        $response = $middleware->handle($request, function () use ($htmlContent) {
            return new Response($htmlContent, 200, ['Content-Type' => 'text/html']);
        });

        return $response->getContent();
    }

    // ─── Host-Based Blocking ───

    public function test_replaces_youtube_iframe_with_placeholder(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'yt-blocker',
            'name' => 'YouTube Blocker',
            'is_active' => true,
            'hosts' => ['youtube.com', 'youtube-nocookie.com'],
        ]);

        $html = '<html><body><iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        // Should contain the placeholder
        $this->assertStringContainsString('class="ycookies-content-blocker"', $result);
        $this->assertStringContainsString('data-ycookies-blocker-id="yt-blocker"', $result);
        $this->assertStringContainsString('data-ycookies-service="youtube"', $result);
        $this->assertStringContainsString('data-ycookies-original=', $result);

        // Original iframe should NOT be present
        $this->assertStringNotContainsString('<iframe src="https://www.youtube.com/embed/abc123"', $result);
    }

    public function test_base64_encoded_original_can_be_decoded(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'yt-test',
            'name' => 'YT',
            'is_active' => true,
            'hosts' => ['youtube.com'],
        ]);

        $originalIframe = '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315"></iframe>';
        $html = '<html><body>'.$originalIframe.'</body></html>';

        $result = $this->runMiddleware($html);

        // Extract the base64-encoded original
        preg_match('/data-ycookies-original="([^"]*)"/', $result, $matches);
        $this->assertNotEmpty($matches[1]);

        $decoded = base64_decode($matches[1]);
        $this->assertEquals($originalIframe, $decoded);
    }

    public function test_blocks_subdomain_matches(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'vimeo-blocker',
            'name' => 'Vimeo',
            'is_active' => true,
            'hosts' => ['vimeo.com'],
        ]);

        $html = '<html><body><iframe src="https://player.vimeo.com/video/12345"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('ycookies-content-blocker', $result);
    }

    // ─── Self-Exclusion ───

    public function test_never_blocks_same_domain_iframes(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'greedy',
            'name' => 'Greedy',
            'is_active' => true,
            'hosts' => ['localhost'],
        ]);

        $html = '<html><body><iframe src="http://localhost/chat-widget"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringNotContainsString('ycookies-content-blocker', $result);
    }

    public function test_never_blocks_ycookies_iframes(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'greedy',
            'name' => 'Greedy',
            'is_active' => true,
            'hosts' => ['ycookies.test'],
        ]);

        $html = '<html><body><iframe src="https://ycookies.test/api/hub/abc"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringNotContainsString('ycookies-content-blocker', $result);
    }

    // ─── No Match ───

    public function test_universal_placeholder_for_iframe_without_matching_blocker(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'yt-only',
            'name' => 'YT Only',
            'is_active' => true,
            'hosts' => ['youtube.com'],
        ]);

        $html = '<html><body><iframe src="https://www.example.com/page"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('ycookies-content-blocker', $result);
        $this->assertStringContainsString('data-ycookies-require-group="external_media"', $result);
        $this->assertStringNotContainsString('src="https://www.example.com/page"', $result);
    }

    // ─── Multiple Embeds ───

    public function test_blocks_multiple_embeds_matching_different_blockers(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'yt-blocker',
            'name' => 'YouTube',
            'is_active' => true,
            'hosts' => ['youtube.com'],
        ]);

        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'vimeo-blocker',
            'name' => 'Vimeo',
            'is_active' => true,
            'hosts' => ['vimeo.com'],
        ]);

        $html = '<html><body>'
            .'<iframe src="https://www.youtube.com/embed/abc"></iframe>'
            .'<iframe src="https://player.vimeo.com/video/123"></iframe>'
            .'<iframe src="https://www.example.com/page"></iframe>'
            .'</body></html>';

        $result = $this->runMiddleware($html);

        $blocked = substr_count($result, 'ycookies-content-blocker');
        $this->assertEquals(3, $blocked); // YouTube + Vimeo + universal for example.com
    }

    // ─── Inactive Blocker ───

    public function test_inactive_blocker_still_triggers_universal_for_matching_host(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'disabled-yt',
            'name' => 'Disabled YT',
            'is_active' => false,
            'hosts' => ['youtube.com'],
        ]);

        $html = '<html><body><iframe src="https://www.youtube.com/embed/abc"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('ycookies-content-blocker', $result);
        $this->assertStringContainsString('data-ycookies-require-group="external_media"', $result);
    }

    public function test_universal_blocks_when_no_content_blockers_exist(): void
    {
        $html = '<html><body><iframe src="https://player.vimeo.com/video/99"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('ycookies-content-blocker', $result);
        $this->assertStringContainsString('universal_external', $result);
    }

    // ─── Placeholder Content ───

    public function test_placeholder_contains_accept_button(): void
    {
        ContentBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'yt-btn-test',
            'name' => 'YouTube',
            'is_active' => true,
            'hosts' => ['youtube.com'],
        ]);

        $html = '<html><body><iframe src="https://www.youtube.com/embed/abc"></iframe></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('Accept', $result);
        $this->assertStringContainsString('button', $result);
    }

    // ─── Non-HTML Response ───

    public function test_skips_json_responses(): void
    {
        $request = Request::create('http://localhost/api/data', 'GET');
        $request->headers->set('Host', 'localhost');

        $middleware = new ContentBlockerMiddleware;

        $response = $middleware->handle($request, function () {
            return new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
        });

        $this->assertEquals('{"ok":true}', $response->getContent());
    }
}
