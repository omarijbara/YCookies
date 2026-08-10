<?php

namespace Tests\Feature;

use App\Http\Middleware\ScriptBlockerMiddleware;
use App\Models\Domain;
use App\Models\Group;
use App\Models\ScriptBlocker;
use App\Models\Service;
use App\Models\CookieGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScriptBlockerMiddlewareTest extends TestCase
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
            'name' => 'Analytics',
            'key' => 'analytics',
            'is_required' => false,
        ]);
        // Attach CookieGroup to Domain via pivot
        $cookieGroup->domains()->attach($this->domain->id);

        $this->service = Service::create([
            'cookie_group_id' => $cookieGroup->id,
            'name' => 'Google Analytics',
            'key' => 'google-analytics',
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
        $request = Request::create('http://' . $host . '/test-page', 'GET');
        $request->headers->set('Host', $host);

        $middleware = new ScriptBlockerMiddleware();

        $response = $middleware->handle($request, function () use ($htmlContent) {
            return new Response($htmlContent, 200, ['Content-Type' => 'text/html']);
        });

        return $response->getContent();
    }

    // ─── Handle-Based Blocking ───

    public function test_blocks_script_matching_handle_by_src(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'ga-blocker',
            'name' => 'GA Blocker',
            'is_active' => true,
            'handles' => ['googletagmanager.com/gtag'],
            'phrases' => [],
        ]);

        $html = '<html><head><script type="text/javascript" src="https://www.googletagmanager.com/gtag/js?id=GA-123"></script></head><body></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('type="text/template"', $result);
        $this->assertStringContainsString('data-ycookies-blocked="true"', $result);
        $this->assertStringContainsString('data-ycookies-blocker-id="ga-blocker"', $result);
        $this->assertStringContainsString('data-ycookies-service="google-analytics"', $result);
        $this->assertStringNotContainsString('type="text/javascript"', $result);
    }

    // ─── Phrase-Based Blocking ───

    public function test_blocks_script_matching_phrase(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'pixel-blocker',
            'name' => 'Pixel Blocker',
            'is_active' => true,
            'handles' => [],
            'phrases' => ['fbevents.js'],
        ]);

        $html = '<html><head><script src="https://connect.facebook.net/en_US/fbevents.js"></script></head><body></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringContainsString('type="text/template"', $result);
        $this->assertStringContainsString('data-ycookies-blocked="true"', $result);
    }

    // ─── Self-Exclusion ───

    public function test_never_blocks_ycookies_own_scripts(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'greedy-blocker',
            'name' => 'Greedy Blocker',
            'is_active' => true,
            'handles' => [],
            'phrases' => ['script'], // Would match everything
        ]);

        $html = '<html><head><script src="/ycookies/manager.js" data-ycookies-site="abc"></script></head><body></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringNotContainsString('data-ycookies-blocked', $result);
        $this->assertStringContainsString('data-ycookies-site="abc"', $result);
    }

    // ─── No Matching Blockers ───

    public function test_passes_through_unmatched_scripts(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'specific-blocker',
            'name' => 'Specific',
            'is_active' => true,
            'handles' => ['googletagmanager.com'],
            'phrases' => [],
        ]);

        $html = '<html><head><script src="/js/app.js"></script></head><body></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringNotContainsString('data-ycookies-blocked', $result);
        $this->assertStringContainsString('src="/js/app.js"', $result);
    }

    // ─── Non-HTML Responses ───

    public function test_skips_non_html_responses(): void
    {
        $request = Request::create('http://localhost/api/data', 'GET');
        $request->headers->set('Host', 'localhost');

        $middleware = new ScriptBlockerMiddleware();

        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'any',
            'name' => 'Any',
            'is_active' => true,
            'handles' => [],
            'phrases' => ['data'],
        ]);

        $response = $middleware->handle($request, function () {
            return new Response('{"data": "json"}', 200, ['Content-Type' => 'application/json']);
        });

        $this->assertEquals('{"data": "json"}', $response->getContent());
    }

    // ─── Inactive Blocker ───

    public function test_ignores_inactive_blockers(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'disabled-blocker',
            'name' => 'Disabled',
            'is_active' => false, // <-- disabled
            'handles' => ['googletagmanager.com'],
            'phrases' => [],
        ]);

        $html = '<html><head><script src="https://www.googletagmanager.com/gtag/js"></script></head><body></body></html>';

        $result = $this->runMiddleware($html);

        $this->assertStringNotContainsString('data-ycookies-blocked', $result);
    }

    // ─── Unknown Domain ───

    public function test_passes_through_for_unknown_domains(): void
    {
        $html = '<html><head><script src="https://tracker.com/track.js"></script></head><body></body></html>';

        // Use a domain that doesn't exist in DB
        $result = $this->runMiddleware($html, 'unknown-domain.com');

        $this->assertStringNotContainsString('data-ycookies-blocked', $result);
    }

    // ─── Multiple Scripts ───

    public function test_blocks_multiple_scripts_independently(): void
    {
        ScriptBlocker::create([
            'domain_id' => $this->domain->id,
            'service_id' => $this->service->id,
            'key' => 'ga-blocker',
            'name' => 'GA',
            'is_active' => true,
            'handles' => ['googletagmanager.com'],
            'phrases' => [],
        ]);

        $html = '<html><head>'
            . '<script src="/js/app.js"></script>'
            . '<script src="https://www.googletagmanager.com/gtag/js"></script>'
            . '<script>console.log("inline")</script>'
            . '</head><body></body></html>';

        $result = $this->runMiddleware($html);

        // Should only block the googletagmanager script
        $blocked = substr_count($result, 'data-ycookies-blocked="true"');
        $this->assertEquals(1, $blocked);

        $this->assertStringContainsString('src="/js/app.js"', $result);
    }
}
