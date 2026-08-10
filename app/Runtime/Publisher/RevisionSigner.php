<?php

declare(strict_types=1);

namespace App\Runtime\Publisher;

use App\Runtime\Schema\ManifestSchema;
use Illuminate\Support\Facades\Log;

/**
 * RevisionSigner — Ed25519 signing and verification for manifest artifacts.
 *
 * Signs the canonical manifest JSON (excluding the signature field) with
 * a private key. Consumers verify using the public key.
 *
 * Key storage: Private key in env var (RUNTIME_SIGNING_KEY).
 * Public key can be derived and served via API endpoint.
 */
class RevisionSigner
{
    private string $privateKey;
    private string $publicKey;
    private bool $useSodium;

    public function __construct()
    {
        $this->useSodium = extension_loaded('sodium');
        $privateKeyPem = config('runtime.signing_private_key');
        
        if (empty($privateKeyPem)) {
            if ($this->useSodium) {
                // PHPUnit: every new RevisionSigner must share the same key (publish vs resolve).
                if (app()->runningUnitTests()) {
                    $seed = hash('sha256', 'ycookies-revision-signer-phpunit-seed-v1', true);
                    $keypair = sodium_crypto_sign_seed_keypair($seed);
                    $this->privateKey = sodium_crypto_sign_secretkey($keypair);
                    $this->publicKey = sodium_crypto_sign_publickey($keypair);
                } else {
                    // Generate ephemeral Ed25519 keypair for local dev without RUNTIME_SIGNING_KEY
                    Log::warning('RevisionSigner: No signing key configured, using ephemeral keypair');
                    $keypair = sodium_crypto_sign_keypair();
                    $this->privateKey = sodium_crypto_sign_secretkey($keypair);
                    $this->publicKey = sodium_crypto_sign_publickey($keypair);
                }
            } else {
                // HMAC-SHA256 fallback when sodium extension is not available
                Log::warning('RevisionSigner: sodium extension not loaded, using HMAC-SHA256 fallback');
                $this->privateKey = hash('sha256', 'ycookies-dev-signing-key-' . config('app.key', 'dev'), true);
                $this->publicKey = $this->privateKey; // Symmetric — same key for sign/verify
            }
            return;
        }

        if (!$this->useSodium) {
            // Production requires sodium for Ed25519
            throw new \RuntimeException('sodium extension is required for Ed25519 signing. Install php-sodium.');
        }

        // Load from PEM / hex / base64 encoded key
        $decoded = $this->decodeKey($privateKeyPem);
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->privateKey = $decoded;
            $this->publicKey = sodium_crypto_sign_publickey_from_secretkey($decoded);
        } elseif (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            // Seed-based key
            $keypair = sodium_crypto_sign_seed_keypair($decoded);
            $this->privateKey = sodium_crypto_sign_secretkey($keypair);
            $this->publicKey = sodium_crypto_sign_publickey($keypair);
        } else {
            throw new \RuntimeException('Invalid signing key length: expected 32 (seed) or 64 (secret key) bytes');
        }
    }

    /**
     * Sign the canonical manifest payload.
     *
     * @param array $manifest  The manifest WITHOUT the signature field
     * @return string Base64-encoded signature (Ed25519 or HMAC-SHA256)
     */
    public function sign(array $manifest): string
    {
        // Remove signature field if present before canonicalization
        unset($manifest['signature']);
        $canonical = ManifestSchema::canonicalize($manifest);

        if ($this->useSodium) {
            $signature = sodium_crypto_sign_detached($canonical, $this->privateKey);
            return base64_encode($signature);
        }

        // HMAC-SHA256 fallback
        return base64_encode(hash_hmac('sha256', $canonical, $this->privateKey, true));
    }

    /**
     * Verify a manifest signature.
     *
     * @param array  $manifest  The full manifest (signature field is stripped internally)
     * @param string $signature Base64-encoded signature
     * @return bool True if signature is valid
     */
    public function verify(array $manifest, string $signature): bool
    {
        unset($manifest['signature']);
        $canonical = ManifestSchema::canonicalize($manifest);
        $sigBytes = base64_decode($signature, true);

        if ($sigBytes === false) {
            return false;
        }

        if ($this->useSodium) {
            if (strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }
            return sodium_crypto_sign_verify_detached($sigBytes, $canonical, $this->publicKey);
        }

        // HMAC-SHA256 fallback
        $expected = hash_hmac('sha256', $canonical, $this->privateKey, true);
        return hash_equals($expected, $sigBytes);
    }

    /**
     * Get the public key for consumer verification.
     *
     * @return string Base64-encoded public key
     */
    public function getPublicKey(): string
    {
        return base64_encode($this->publicKey);
    }

    /**
     * Decode a key from various formats (base64, hex, raw).
     */
    private function decodeKey(string $key): string
    {
        $key = trim($key);

        // Try base64 first
        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return $decoded;
        }

        // Try hex
        if (ctype_xdigit($key) && strlen($key) >= 64) {
            return hex2bin($key);
        }

        // Raw bytes
        return $key;
    }
}
