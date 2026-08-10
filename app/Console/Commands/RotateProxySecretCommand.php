<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class RotateProxySecretCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proxy:rotate-secret {--force : Force the operation to run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate the proxy HMAC shared secret with a 24-hour grace period';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->warn('This will rotate the cryptographic HMAC secret used between Laravel and the Node proxy.');
        
        if (!$this->option('force') && !$this->confirm('Are you sure you want to rotate the PROXY_SHARED_SECRET?')) {
            $this->info('Rotation cancelled.');
            return;
        }

        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            $this->error('The .env file does not exist.');
            return;
        }

        $currentEnv = File::get($envPath);

        // Extract current secret
        $currentSecret = env('PROXY_SHARED_SECRET');
        
        if (!$currentSecret) {
            $currentSecret = $this->extractEnvVar($currentEnv, 'PROXY_SHARED_SECRET');
        }

        $newSecret = Str::random(64);

        if ($currentSecret) {
            $this->info('Found existing secret. Moving to PROXY_SHARED_SECRET_PREV for grace period...');
            $currentEnv = $this->updateEnvVar($currentEnv, 'PROXY_SHARED_SECRET_PREV', $currentSecret);
        } else {
            $this->info('No existing secret found. Fresh deployment.');
            $currentEnv = $this->updateEnvVar($currentEnv, 'PROXY_SHARED_SECRET_PREV', '');
        }

        // Apply novel secret
        $currentEnv = $this->updateEnvVar($currentEnv, 'PROXY_SHARED_SECRET', $newSecret);

        File::put($envPath, $currentEnv);

        Artisan::call('config:clear');

        $this->info("✅ Proxy secrets aggressively rotated!");
        $this->newLine();
        $this->line('<bg=green;fg=white> NEW SECRET: </> ' . $newSecret);
        $this->line('<bg=cyan;fg=white> GRACE SECRET: </> ' . ($currentSecret ?: '(none)'));
        $this->newLine();
        
        $this->warn('⚠️ CRITICAL: YOU MUST UPDATE COOLIFY!');
        $this->line('1. Go to your Coolify Dashboard');
        $this->line('2. Open the Admin App and Proxy App environment variables.');
        $this->line('3. Update SERVICE_BASE64_64_PROXY, PROXY_SHARED_SECRET, and PROXY_SHARED_SECRET_PREV.');
        $this->line('4. Redeploy BOTH containers. The Node proxy will seamlessly accept signatures from both keys.');
    }

    /**
     * Extract a variable from the .env string manually.
     */
    private function extractEnvVar(string $envTemplate, string $key): ?string
    {
        $pattern = "/^{$key}=(.*)$/m";
        if (preg_match($pattern, $envTemplate, $matches)) {
            return trim($matches[1], "\"'");
        }
        return null;
    }

    /**
     * Update or append a variable in the .env string.
     */
    private function updateEnvVar(string $envTemplate, string $key, string $value): string
    {
        $pattern = "/^{$key}=(.*)$/m";
        
        $escapedValue = preg_match('/\s/', $value) ? "\"{$value}\"" : $value;
        
        if (preg_match($pattern, $envTemplate)) {
            return preg_replace($pattern, "{$key}={$escapedValue}", $envTemplate);
        }

        return $envTemplate . PHP_EOL . "{$key}={$escapedValue}";
    }
}
