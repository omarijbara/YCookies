<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class CoolifySetting extends Model
{
    protected $fillable = [
        'instance_url',
        'api_token',
        'app_uuids',
        'primary_proxy_uuid',
        'is_active',
        // SSH fields
        'ssh_private_key',
        'ssh_public_key',
        'ssh_host',
        'ssh_port',
        'ssh_user',
        'ssh_is_active',
        'ssh_tested_at',
        'ssh_test_status',
        'ssh_auto_cleanup_enabled',
        'ssh_auto_cleanup_interval',
        'ssh_auto_cleanup_threshold',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'app_uuids'     => 'array',
        'ssh_is_active'  => 'boolean',
        'ssh_port'       => 'integer',
        'ssh_tested_at'  => 'datetime',
        'ssh_auto_cleanup_enabled' => 'boolean',
        'ssh_auto_cleanup_interval' => 'integer',
        'ssh_auto_cleanup_threshold' => 'integer',
    ];

    /**
     * Get the singleton settings instance (creates one if none exists).
     */
    public static function instance(): static
    {
        return static::firstOrCreate([], [
            'instance_url' => env('COOLIFY_INSTANCE_URL', 'https://coolify.revyome.com'),
            'api_token'    => null,
            'app_uuids'    => null,
            'primary_proxy_uuid' => null,
            'is_active'    => false,
        ]);
    }

    // ── Coolify API token (encrypted) ───────────────────────

    /**
     * Encrypt API token on set.
     */
    public function setApiTokenAttribute($value): void
    {
        $this->attributes['api_token'] = $value ? Crypt::encryptString($value) : '';
    }

    /**
     * Decrypt API token on get.
     */
    public function getDecryptedApiTokenAttribute(): string
    {
        try {
            return !empty($this->attributes['api_token'])
                ? Crypt::decryptString($this->attributes['api_token'])
                : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if Coolify API is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->is_active && !empty($this->decrypted_api_token) && !empty($this->instance_url);
    }

    // ── SSH private key (encrypted) ─────────────────────────

    /**
     * Encrypt SSH private key on set.
     */
    public function setSshPrivateKeyAttribute($value): void
    {
        $this->attributes['ssh_private_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt SSH private key on get.
     */
    public function getDecryptedSshPrivateKeyAttribute(): ?string
    {
        try {
            return !empty($this->attributes['ssh_private_key'])
                ? Crypt::decryptString($this->attributes['ssh_private_key'])
                : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if SSH server access is fully configured and testable.
     */
    public function isSshConfigured(): bool
    {
        return $this->ssh_is_active
            && !empty($this->ssh_host)
            && !empty($this->attributes['ssh_private_key']);
    }

    /**
     * Generate a new ed25519 SSH key pair.
     *
     * @return array{public_key: string, fingerprint: string}
     * @throws \RuntimeException
     */
    public function generateSshKeyPair(): array
    {
        $tmpDir = sys_get_temp_dir() . '/ycookies_keygen_' . uniqid();
        @mkdir($tmpDir, 0700, true);
        $keyPath = $tmpDir . '/id_ed25519';

        try {
            $cmd = sprintf(
                'ssh-keygen -t ed25519 -f %s -N "" -C "ycookies-server-cleanup" 2>&1',
                escapeshellarg($keyPath)
            );
            $output = shell_exec($cmd);

            if (!is_file($keyPath) || !is_file($keyPath . '.pub')) {
                throw new \RuntimeException("ssh-keygen failed: {$output}");
            }

            $privateKey = file_get_contents($keyPath);
            $publicKey  = trim(file_get_contents($keyPath . '.pub'));

            // Extract fingerprint
            $fpCmd = sprintf('ssh-keygen -lf %s 2>&1', escapeshellarg($keyPath . '.pub'));
            $fpOutput = trim(shell_exec($fpCmd) ?? '');
            $fingerprint = $fpOutput ?: 'unknown';

            // Store encrypted in DB
            $this->ssh_private_key = $privateKey;
            $this->ssh_public_key  = $publicKey;
            $this->save();

            return [
                'public_key'  => $publicKey,
                'fingerprint' => $fingerprint,
            ];
        } finally {
            // Clean up temp files
            @unlink($keyPath);
            @unlink($keyPath . '.pub');
            @rmdir($tmpDir);
        }
    }

    /**
     * Remove SSH key pair and deactivate SSH access.
     */
    public function removeSshAccess(): void
    {
        $this->update([
            'ssh_private_key' => null,
            'ssh_public_key'  => null,
            'ssh_is_active'   => false,
            'ssh_tested_at'   => null,
            'ssh_test_status' => null,
        ]);

        // Remove cached key files from container (all legacy and current paths)
        @unlink('/tmp/.ssh_server_cleanup_key');
        @unlink(storage_path('app/ssh_server_key_' . getmyuid() . '.pem'));
        @unlink('/tmp/ssh_server_key_' . getmyuid() . '.pem');
    }

    /**
     * Get the SSH key fingerprint for display.
     */
    public function getSshKeyFingerprintAttribute(): ?string
    {
        if (empty($this->ssh_public_key)) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ssh_fp_');
        file_put_contents($tmpFile, $this->ssh_public_key);

        $output = trim(shell_exec(sprintf('ssh-keygen -lf %s 2>&1', escapeshellarg($tmpFile))) ?? '');
        @unlink($tmpFile);

        return $output ?: null;
    }

    /**
     * Get the list of allowed SSH commands for server cleanup.
     */
    public static function allowedSshCommands(): array
    {
        return [
            'docker system df'       => 'Show Docker disk usage',
            'docker image prune'     => 'Remove unused images',
            'docker container prune' => 'Remove stopped containers',
            'docker volume prune'    => 'Remove unused volumes',
            'docker builder prune'   => 'Clear build cache',
            'docker system prune'    => 'Full system cleanup',
            'journalctl --vacuum-time' => 'Rotate system logs',
        ];
    }
}
