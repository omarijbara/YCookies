<?php

namespace Tests\Feature;

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CspHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ycookies_preview_emits_content_security_policy_when_csp_enabled(): void
    {
        config(['csp.enabled' => true]);

        $domain = Domain::factory()->create([
            'site_id' => 'csp_test_site',
            'is_active' => true,
        ]);

        $response = $this->get('/ycookies/preview?site_id='.$domain->site_id);

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');
        $header = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($header);
        $this->assertStringContainsString('script-src', $header);
        $this->assertStringContainsString("'self'", $header);
    }

    #[Test]
    public function ycookies_preview_omits_csp_header_when_csp_disabled(): void
    {
        config(['csp.enabled' => false]);

        $domain = Domain::factory()->create([
            'site_id' => 'csp_off_site',
            'is_active' => true,
        ]);

        $response = $this->get('/ycookies/preview?site_id='.$domain->site_id);

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }
}
