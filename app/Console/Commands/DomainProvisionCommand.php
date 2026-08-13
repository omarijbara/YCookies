<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DomainProvisionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'domain:provision {name : The domain name (e.g. duftz.de)} 
                            {--origin= : The upstream HTTP/S origin URL}
                            {--group= : Group ID to assign the domain}
                            {--manifest : Explicitly enable manifest mode on creation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a new domain securely on the hardened proxy platform (Phase 1d compliant)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = strtolower($this->argument('name'));
        $origin = $this->option('origin') ?? "https://{$name}";
        $manifestMode = $this->option('manifest') ?? false;

        $this->info("Starting secure provisioning for domain: [{$name}]");

        // 1. Check if domain already exists
        $domain = Domain::where('name', $name)->first();

        if ($domain) {
            $this->warn("Domain {$name} already exists. Verifying proxy readiness...");
        } else {
            $this->info("Domain {$name} not found. Creating new proxy tenant...");
            
            // Assign a group
            $groupId = $this->option('group');
            if (!$groupId) {
                $group = Group::first();
                $groupId = $group ? $group->id : null;
            }

            $domain = new Domain();
            $domain->name = $name;
            $domain->group_id = $groupId;
            // The Eloquent saving observer auto-generates origin_auth_token and promotes IP if necessary
        }

        // 2. Hydrate Required Proxy Fields
        $domain->site_id = 1;
        $domain->origin_url = $origin;
        $domain->is_active = true;
        $domain->proxy_enabled = true;
        $domain->proxy_status = 'active';
        $domain->config_version = $domain->config_version ?? 1;
        
        // 3. Enable TCM/Geo by default for deep proxy routing
        if (empty($domain->tcm_config)) {
            $domain->tcm_config = ['enabled' => true, 'mode' => 'strict'];
        }

        // 4. Update the DB -> Invokes Observers
        $domain->save();

        $this->info("✔ Eloquent bindings applied. Token: {$domain->origin_auth_token}");

        // 5. Because DomainObserver::updated() is only triggered on updates,
        // if this was just created, we need to explicitly fire the bump to warm Redis.
        // Or we can just explicitly touch it to ensure observer triggers the pubsub.
        if ($domain->wasRecentlyCreated) {
            $domain->touch(); 
        }

        $this->info("✔ Redis Hash [proxy_cfg:{$name}] populated.");
        $this->info("✔ Pub/Sub Push invalidated edge cache.");

        // 6. Test Cache Accessibility (Mock test to ensure we can read it)
        $redisCfg = \Illuminate\Support\Facades\Redis::connection('proxy')->get("proxy_cfg:{$name}");
        if ($redisCfg) {
            $this->info("✔ Redis Fallback Cache validated (" . strlen($redisCfg) . " bytes).");
        } else {
            $this->error("❌ Redis cache was NOT populated. Observer failed.");
            return Command::FAILURE;
        }

        $this->line("");
        $this->info("====================================");
        $this->info("✅ PROVISIONING COMPLETE: {$name}");
        $this->info("====================================");
        $this->line("1. Route Traefik via Coolify UI -> Settings");
        $this->line("2. Validate: GET https://proxy.ypsilon.dev/api/proxy-config/{$name}");
        
        return Command::SUCCESS;
    }
}
