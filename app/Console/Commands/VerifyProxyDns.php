<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\CoolifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies DNS records for proxy-enabled domains and registers them with Coolify/Traefik.
 * Scheduled to run every 5 minutes.
 */
class VerifyProxyDns extends Command
{
    protected $signature = 'ycookies:verify-proxy-dns';
    protected $description = 'Verify DNS records for proxy-enabled domains and register with Coolify';

    public function handle(CoolifyService $coolify): int
    {
        $domains = Domain::withoutGlobalScopes()
            ->where('proxy_enabled', true)
            ->where(function ($query) {
                $query->whereNotNull('origin_url')
                    ->orWhereNotNull('origin_ip')
                    ->orWhereNotNull('origin_subdomain');
            })
            ->get();

        if ($domains->isEmpty()) {
            $this->info('No proxy-enabled domains found.');
            return self::SUCCESS;
        }

        $this->info("Checking {$domains->count()} proxy-enabled domain(s)...");

        foreach ($domains as $domain) {
            $this->line("  Checking: {$domain->name}");

            $dnsOk = $coolify->verifyDns($domain->name);

            if ($dnsOk) {
                if ($domain->proxy_status !== 'active') {
                    // DNS verified — register with Coolify if not already
                    $coolifyOk = $coolify->addDomainToApp($domain->name, true);

                    if ($coolifyOk) {
                        // proxy_status is a config-relevant field — use model update
                        // so DomainObserver fires and bumps config_version correctly.
                        $domain->update([
                            'proxy_status' => 'active',
                            'proxy_verified_at' => now(),
                        ]);
                        $this->info("    ✅ DNS verified, registered with Coolify: {$domain->name}");
                        Log::info("[VerifyProxyDns] Domain activated: {$domain->name}");
                    } else {
                        // ssl_pending is also config-relevant (proxy_status change)
                        $domain->update(['proxy_status' => 'ssl_pending']);
                        $this->warn("    ⏳ DNS OK but Coolify registration pending: {$domain->name}");
                    }
                } else {
                    // Already active — only refresh verification timestamp.
                    // Use raw DB update to bypass DomainObserver (no config change).
                    DB::table('domains')
                        ->where('id', $domain->id)
                        ->update(['proxy_verified_at' => now()]);
                    $this->info("    ✅ Still active: {$domain->name}");
                }
            } else {
                if ($domain->proxy_status === 'active') {
                    // Was active but DNS no longer resolves — keep active but warn
                    $this->warn("    ⚠️  DNS no longer resolves (keeping active): {$domain->name}");
                    Log::warning("[VerifyProxyDns] DNS issue for active domain: {$domain->name}");
                } else {
                    // dns_error is a config-relevant proxy_status change
                    $domain->update(['proxy_status' => 'dns_error']);
                    $this->error("    ❌ DNS not pointing to YCookies: {$domain->name}");
                }
            }
        }

        return self::SUCCESS;
    }
}
