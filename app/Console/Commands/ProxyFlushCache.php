<?php

namespace App\Console\Commands;

use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Deploy-time cache flush for the Node proxy.
 *
 * Run this after deploys that change environment variables (APP_URL, etc.)
 * or after any change that affects proxy config but doesn't touch a Domain row.
 *
 * What it does:
 * 1. Bumps config_version on ALL proxy-enabled domains (atomic DB update)
 * 2. Clears all Laravel proxy_config:* cache keys
 * 3. Broadcasts Redis invalidation for every affected domain
 *
 * Usage:
 *   php artisan proxy:flush-cache
 *   php artisan proxy:flush-cache --domain=duftz.de
 */
class ProxyFlushCache extends Command
{
    protected $signature = 'proxy:flush-cache
                            {--domain= : Flush only a specific domain (by hostname)}';

    protected $description = 'Flush proxy config cache and broadcast Redis invalidation to Node proxy';

    public function handle(): int
    {
        $query = Domain::withoutGlobalScopes()
            ->where('proxy_enabled', true);

        if ($hostname = $this->option('domain')) {
            $query->where('name', $hostname);
        }

        $domains = $query->get(['id', 'name', 'config_version']);

        if ($domains->isEmpty()) {
            $this->warn('No proxy-enabled domains found.');
            return self::SUCCESS;
        }

        // Atomic bump: single UPDATE for all domains
        $ids = $domains->pluck('id')->toArray();
        DB::table('domains')
            ->whereIn('id', $ids)
            ->increment('config_version');

        $this->info("Bumped config_version for {$domains->count()} domain(s).");

        // Clear Laravel cache + broadcast Redis for each domain
        $redisErrors = 0;
        foreach ($domains as $domain) {
            Cache::forget("proxy_config:{$domain->name}");

            try {
                $payload = json_encode([
                    'host' => $domain->name,
                    'version' => $domain->config_version + 1,
                ]);
                Redis::connection('pubsub')->publish('domain-config-updated', $payload);
            } catch (\Throwable $e) {
                $redisErrors++;
                $this->warn("  Redis publish failed for {$domain->name}: {$e->getMessage()}");
            }

            $this->line("  ✓ {$domain->name}");
        }

        if ($redisErrors > 0) {
            $this->warn("Redis publish failed for {$redisErrors} domain(s). Node proxy will pick up changes on next TTL expiry.");
        } else {
            $this->info('Redis invalidation broadcast complete.');
        }

        return self::SUCCESS;
    }
}
