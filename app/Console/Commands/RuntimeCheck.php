<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Startup readiness check for the manifest runtime subsystem.
 *
 * Validates:
 *   1. Sodium extension availability when a signing key is configured
 *   2. Signing key presence in production environments
 *   3. Signing key format/length correctness
 *
 * Intended for container health probes and CI pre-deploy validation.
 *
 * Usage:
 *   php artisan runtime:check
 */
class RuntimeCheck extends Command
{
    protected $signature = 'runtime:check';

    protected $description = 'Validate runtime manifest subsystem readiness (signing key, sodium, config)';

    public function handle(): int
    {
        $this->info('═══ Runtime Subsystem Health Check ═══');
        $this->newLine();

        $failures = 0;

        // ── 1. Sodium extension ──────────────────────────────────
        $hasSodium = extension_loaded('sodium');
        if ($hasSodium) {
            $this->line('  ✓ sodium extension loaded');
        } else {
            $this->error('  ✗ sodium extension NOT loaded');
            $this->line('    Ed25519 signing requires the sodium PHP extension.');
            $this->line('    Install: apt-get install php-sodium && service php-fpm restart');
            $failures++;
        }

        // ── 2. Signing key presence ──────────────────────────────
        $signingKey = config('runtime.signing_private_key');
        $isProduction = app()->environment('production');

        if (!empty($signingKey)) {
            $this->line('  ✓ RUNTIME_SIGNING_KEY is configured');

            // Validate key can be decoded
            if ($hasSodium) {
                try {
                    $decoded = $this->decodeKey($signingKey);
                    $len = strlen($decoded);

                    if ($len === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                        $this->line("  ✓ signing key: 64-byte secret key");
                    } elseif ($len === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                        $this->line("  ✓ signing key: 32-byte seed");
                    } else {
                        $this->error("  ✗ signing key: unexpected length ({$len} bytes, expected 32 or 64)");
                        $failures++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  ✗ signing key decode failed: {$e->getMessage()}");
                    $failures++;
                }
            }
        } else {
            if ($isProduction) {
                $this->error('  ✗ RUNTIME_SIGNING_KEY is EMPTY in production');
                $this->line('    Set RUNTIME_SIGNING_KEY in Coolify env vars.');
                $this->line('    Without it, an ephemeral keypair is used — signatures change on restart.');
                $failures++;
            } else {
                $this->warn('  ⚠ RUNTIME_SIGNING_KEY is empty (ephemeral keypair will be used)');
                $this->line('    This is acceptable for dev/test, not for production.');
            }
        }

        // ── 3. Sodium + key combo ────────────────────────────────
        if (!$hasSodium && !empty($signingKey)) {
            $this->error('  ✗ FATAL: Signing key is configured but sodium is not available');
            $this->line('    RevisionSigner will throw RuntimeException on container boot.');
            $failures++;
        }

        // ── 4. Verify-on-read config ─────────────────────────────
        $verifyOnRead = config('runtime.verify_on_read', true);
        if ($verifyOnRead) {
            $this->line('  ✓ MANIFEST_VERIFY_ON_READ = true (signatures verified at read time)');
        } else {
            $this->warn('  ⚠ MANIFEST_VERIFY_ON_READ = false (VERIFICATION BYPASSED)');
            $this->line('    This should only be false during emergency triage.');
        }

        // ── Summary ──────────────────────────────────────────────
        $this->newLine();
        if ($failures > 0) {
            $this->error("✗ {$failures} issue(s) found. Container may not be ready for manifest operations.");
            Log::error("runtime:check failed with {$failures} issue(s)");
            return self::FAILURE;
        }

        $this->info('✓ All runtime checks passed.');
        Log::info('runtime:check passed');
        return self::SUCCESS;
    }

    /**
     * Decode a key from various formats (mirrors RevisionSigner::decodeKey).
     */
    private function decodeKey(string $key): string
    {
        $key = trim($key);

        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return $decoded;
        }

        if (ctype_xdigit($key) && strlen($key) >= 64) {
            return hex2bin($key);
        }

        return $key;
    }
}
