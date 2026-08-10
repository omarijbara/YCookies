# Manifest Runtime — Operator Runbook

## Quick Reference

| Command | Purpose |
|---------|---------|
| `php artisan runtime:check` | Validate signing key, sodium, config readiness |
| `php artisan manifest:metrics` | View runtime metrics (verification, cache, Redis) |
| `php artisan manifest:metrics --json` | JSON output for monitoring |
| `php artisan manifest:metrics --reset` | Reset all metric counters |
| `php artisan manifest:resolver:invalidate {domain}` | Bust resolver cache for a domain |
| `php artisan manifest:resolver:invalidate --all` | Bust resolver cache for all domains |
| `php artisan manifest:rollout:plan` | Read-only: show domain eligibility |
| `php artisan manifest:rollout:execute --domains=x` | Compile + verify + publish for domains |
| `php artisan runtime:rollback {domain} {revision}` | Rollback to a previous revision |

---

## Emergency: Toggle MANIFEST_VERIFY_ON_READ

### When to disable

Only disable signature verification if:
- A signing key rotation or misconfiguration causes all manifest-enabled domains to fall back to legacy
- The fallback itself is causing issues (e.g., legacy DB queries are overwhelming the database)
- You need to restore manifest-path serving while investigating the root cause

### Who is allowed

Infrastructure or DevOps personnel with Coolify env var access.

### How to disable

```bash
# In Coolify dashboard → ycookies-admin-ycookies → Environment Variables
# Set:
MANIFEST_VERIFY_ON_READ=false

# Then restart the container or wait for next deploy.
# Alternatively, via Coolify API:
curl -X POST "https://coolify.revyome.com/api/v1/applications/m9ejgstxl3t17m4ifq6ht1us/restart" \
  -H "Authorization: Bearer $COOLIFY_API_TOKEN"
```

### After disabling

1. **Immediately investigate** the verification failure root cause
2. Check `php artisan manifest:metrics --json` for `verification_failures` count
3. Check Laravel logs for `RevisionResolver: signature verification failed` entries
4. Fix the root cause (usually: wrong signing key, corrupt manifest, sodium missing)
5. **Re-enable**: set `MANIFEST_VERIFY_ON_READ=true` and restart
6. Confirm: `php artisan manifest:metrics` shows `verification_successes` incrementing

---

## Triage: published_unverified

A `published_unverified` status means the revision **was published** (transaction committed, pointer moved) but the post-publish re-read or signature verification failed. The revision is live.

### Investigation steps

1. **Check metrics:**
   ```bash
   php artisan manifest:metrics --json | grep publish_unverified
   ```

2. **Check logs:**
   ```bash
   # Search for the specific domain
   grep "post-publish" storage/logs/laravel.log | tail -20
   grep "signature verification failed after publish" storage/logs/laravel.log | tail -20
   ```

3. **Inspect the revision directly:**
   ```bash
   php artisan tinker --execute="
     \$rev = \App\Models\RuntimeRevision::where('domain_id', \$domainId)->latest()->first();
     echo json_encode([
       'id' => \$rev->id,
       'revision' => \$rev->revision_number,
       'hash' => \$rev->manifest_hash,
       'has_signature' => !empty(\$rev->manifest_signature),
     ]);
   "
   ```

4. **Verify the manifest signature manually:**
   ```bash
   php artisan tinker --execute="
     \$rev = \App\Models\RuntimeRevision::find(\$revisionId);
     \$manifest = json_decode(\$rev->manifest_json, true);
     \$signer = app(\App\Runtime\Publisher\RevisionSigner::class);
     echo \$signer->verify(\$manifest, \$manifest['signature']) ? 'VALID' : 'INVALID';
   "
   ```

### Recovery

- **If signature is valid now** (transient issue): invalidate cache and move on
  ```bash
  php artisan manifest:resolver:invalidate {domain}
  ```

- **If signing key was misconfigured**: fix `RUNTIME_SIGNING_KEY`, then re-publish:
  ```bash
  php artisan manifest:rollout:domain {domain}
  ```

- **If you must temporarily bypass**: toggle `MANIFEST_VERIFY_ON_READ=false` (see above), but restore immediately.

---

## Manually Invalidate Resolver Cache

### Via artisan command (preferred)

```bash
# Single domain
php artisan manifest:resolver:invalidate duftz.de

# All manifest-enabled domains
php artisan manifest:resolver:invalidate --all
```

### Via tinker (if command is unavailable)

```bash
php artisan tinker --execute="app(\App\Runtime\Consumer\RevisionResolver::class)->invalidate('duftz.de')"
```

---

## Signing Key Rotation

> **Current policy:** A single Ed25519 seed is stored in `RUNTIME_SIGNING_KEY`. All manifests are signed with the derived keypair. There is no public-key distribution to external consumers — verification is internal (Laravel-side only).

### Rotation steps

1. Generate a new 32-byte seed:
   ```bash
   php -r "echo base64_encode(random_bytes(32));"
   ```

2. Set the new key in Coolify env vars as `RUNTIME_SIGNING_KEY`

3. Deploy (container restart picks up the new key)

4. Re-publish all manifest-enabled domains to re-sign with the new key:
   ```bash
   php artisan manifest:rollout:execute --all --force
   ```

5. Verify:
   ```bash
   php artisan manifest:metrics --json | grep verification
   # verification_failures should be 0
   # verification_successes should be incrementing
   ```

6. Invalidate resolver cache to force re-verification:
   ```bash
   php artisan manifest:resolver:invalidate --all
   ```

### Rollback

If the rotation causes failures, revert `RUNTIME_SIGNING_KEY` to the previous value, restart, and re-publish.

---

## Monitoring

### Metrics to watch

| Metric | Normal | Alert Threshold |
|--------|--------|-----------------|
| `verification_failures` | 0 | > 0 |
| `verification_missing_signature` | 0 | > 0 |
| `publish_unverified` | 0 | > 0 |
| `redis_mirror_failures` | 0 | > 5 in 10 min |
| `redis_pubsub_failures` | 0 | > 5 in 10 min |
| `resolver_cache_hit_ratio` | > 90% | < 50% |

### Structured log output

```bash
php artisan manifest:metrics --emit-log
```

This emits a single structured log line (`manifest_metrics`) with all counter values, suitable for log aggregators (Loki, Datadog, etc.).

### Health check

```bash
php artisan runtime:check
# Exit code 0 = healthy, 1 = issues found
```

Use as a container readiness probe or CI pre-deploy gate.

---

## CI / Staging Checks

### Pre-deploy gate

Run `runtime:check` as a CI step before deploying to production. The command exits with code 0 (healthy) or 1 (issues found).

```bash
# In your deploy pipeline or staging smoke:
php artisan runtime:check
# Exit code 0 → proceed with deploy
# Exit code 1 → abort and investigate
```

### GitHub Actions example

```yaml
# .github/workflows/deploy.yml (excerpt)
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      # ... (install PHP, composer install, etc.)

      - name: Runtime readiness check
        run: php artisan runtime:check
        # Fails the job if sodium is missing, signing key is
        # unconfigured in production, or verify_on_read is off.

      - name: Manifest metrics snapshot
        run: php artisan manifest:metrics --json
        # Optional: captures pre-deploy baseline for comparison.

      - name: Deploy
        run: # ... your deploy command
```

### Staging smoke (manual)

After deploying to staging, run the following sequence to validate the manifest subsystem:

```bash
# 1. Health check
php artisan runtime:check

# 2. Metrics baseline
php artisan manifest:metrics --json

# 3. Resolve a manifest-enabled domain
php artisan tinker --execute="var_dump(app(\App\Runtime\Consumer\RevisionResolver::class)->resolveActive('your-domain.com') !== null)"

# 4. Check metrics moved (cache miss → verification success)
php artisan manifest:metrics --json
```

### Notes

- In **production**, `runtime:check` will fail (exit 1) if `RUNTIME_SIGNING_KEY` is empty.
- In **dev/test**, an empty signing key is acceptable (warned, not fatal).
- `sentinel_active` is now a live gauge in `manifest:metrics` — use it to detect how many domains are in verification-failed state.

### Automated smoke runner

A reusable staging smoke script is available at:

```
ops/artifacts/manifest-hardening/run-staging-smoke.sh
```

Run it manually:

```bash
# Against the current production Laravel container:
bash ops/artifacts/manifest-hardening/run-staging-smoke.sh

# Or specify a container name:
bash ops/artifacts/manifest-hardening/run-staging-smoke.sh laravel-m9ejgstxl3t17m4ifq6ht1us-145807597138
```

### GitHub Actions — CI readiness probe

```yaml
# .github/workflows/ci.yml (add this job)
jobs:
  manifest-readiness:
    name: Manifest Runtime Readiness
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: sodium

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Runtime readiness check
        run: php artisan runtime:check
        env:
          RUNTIME_SIGNING_KEY: ${{ secrets.RUNTIME_SIGNING_KEY }}
          MANIFEST_VERIFY_ON_READ: 'true'
        # Exit code 0 = healthy; exit code 1 = abort deploy.

      - name: Manifest metrics snapshot
        run: php artisan manifest:metrics --json
        continue-on-error: true
```

### GitHub Actions — manual smoke trigger

```yaml
# .github/workflows/staging-smoke.yml
name: Manifest Staging Smoke
on:
  workflow_dispatch:
    inputs:
      domain:
        description: 'Domain to smoke test'
        required: true
        default: 'duftz.de'

jobs:
  smoke-manifest:
    name: Smoke Test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run staging smoke
        run: bash ops/artifacts/manifest-hardening/run-staging-smoke.sh
        env:
          SSH_KEY: ${{ secrets.PRODUCTION_SSH_KEY }}
```
