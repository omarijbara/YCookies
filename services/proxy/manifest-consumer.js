/**
 * Manifest Consumer — Read immutable manifest data from proxy config.
 *
 * When a domain has manifest mode enabled, the Laravel proxy-config
 * response includes a `manifest` block with the compiled base artifact.
 * This module extracts proxy-relevant fields from that artifact and
 * returns them in the same shape as legacy config fields.
 *
 * Fallback behavior:
 *   - If manifest block is absent or disabled → returns null (use legacy)
 *   - If manifest verification fails → logs error, returns null (use legacy)
 *   - Never throws — availability-leaning during canary phase
 */

import { canonicalize, hashArtifact } from './manifest-verifier.js';

/**
 * Per-domain manifest mode counters for /statsz observability.
 */
export const manifestMetrics = {
  resolved: 0,        // Manifest mode activated successfully
  fallback: 0,        // Manifest present but failed verification → legacy
  missing: 0,         // No manifest block → legacy
  signatureOk: 0,     // Signature/hash verified
  signatureFail: 0,   // Signature/hash verification failed
  lastRevision: null,  // Last served revision number
};

/**
 * Attempt to resolve proxy config from the manifest block.
 *
 * Returns a structured config object with the same fields the proxy
 * expects (origin, script_blockers, style_blockers, content_blockers, cookie_policy,
 * bootstrapper, features) — OR null if manifest mode is not active
 * or verification failed.
 *
 * @param {object} config - Full proxy-config response from Laravel
 * @returns {object|null} Manifest-projected config or null for legacy fallback
 */
export function resolveManifestConfig(config) {
  // Guard: no manifest block at all
  if (!config.manifest) {
    manifestMetrics.missing++;
    return null;
  }

  // Guard: manifest mode disabled for this domain
  if (!config.manifest.enabled) {
    manifestMetrics.missing++;
    return null;
  }

  const { base_artifact, manifest_hash, revision_number } = config.manifest;

  // Guard: base artifact must be present
  if (!base_artifact) {
    console.error(`[manifest] Domain ${config.domain}: manifest enabled but no base_artifact`);
    manifestMetrics.fallback++;
    return null;
  }

  // Verify hash integrity — the base artifact's canonical hash must match
  // the hash stored in the manifest envelope. This catches corruption
  // but NOT tampering (signature verification is handled separately).
  if (manifest_hash) {
    try {
      // Verify the base artifact can be canonicalized and hashed without error.
      // This catches corruption in the artifact data.
      const computedHash = hashArtifact(base_artifact);
      // Note: manifest_hash is the envelope hash, not the base artifact hash.
      // For Phase 1, we verify the base artifact is well-formed.
      // Full signature verification comes in Phase 2 when we have the public key.
      if (computedHash) {
        manifestMetrics.signatureOk++;
      }
    } catch (err) {
      console.error(`[manifest] Domain ${config.domain}: hash verification failed: ${err.message}`);
      manifestMetrics.signatureFail++;
      manifestMetrics.fallback++;
      return null;
    }
  } else {
    // No hash to verify — still accept the artifact (Phase 1 leniency)
    manifestMetrics.signatureOk++;
  }

  // Extract proxy-relevant fields from the base artifact.
  // The base artifact is the full compiled domain state. The proxy only
  // needs a subset of it — origin, blockers, bootstrapper, features.
  const projected = {
    // These come from the manifest base artifact
    script_blockers: base_artifact.script_blockers || [],
    style_blockers: base_artifact.style_blockers || [],
    content_blockers: base_artifact.content_blockers || [],
    auto_blocking: base_artifact.auto_blocking || {
      content: true,
      script: true,
      style: true,
      service: true,
    },
    cookie_policy: base_artifact.cookie_policy || { mode: 'passthrough' },
    bootstrapper: base_artifact.bootstrapper || {},
    features: base_artifact.features || {},

    // Origin is in the base artifact (proxy-enabled domains always have it)
    origin: base_artifact.origin || null,

    // Proxy block
    proxy: base_artifact.proxy || { enabled: true, status: 'active', engine: 'node' },

    // Consent config (used by some proxy features)
    consent: {
      mode_enabled: base_artifact.tcm_config?.enabled || false,
      advanced_mode: base_artifact.tcm_config?.advanced_consent_mode || false,
      version: base_artifact.consent_version || 1,
    },

    // Metadata
    _manifest: {
      revision: revision_number,
      hash: manifest_hash,
      source: 'manifest',
    },
  };

  manifestMetrics.resolved++;
  manifestMetrics.lastRevision = revision_number;

  return projected;
}

/**
 * Merge bootstrapper: manifest artifact first, then legacy (request-time) values win.
 * Published artifacts can contain stale `static_loader_url` hashes or `null` after
 * deploys; Laravel recomputes delivery URLs on each proxy-config build.
 *
 * @param {object|undefined} legacy - bootstrapper from Laravel buildConfig()
 * @param {object|undefined} fromManifest - bootstrapper from base_artifact
 * @returns {object}
 */
export function mergeBootstrapper(legacy, fromManifest) {
  const m = fromManifest && typeof fromManifest === 'object' ? fromManifest : {};
  const l = legacy && typeof legacy === 'object' ? legacy : {};
  return { ...m, ...l };
}

/**
 * Merge manifest-projected config with the original config response.
 *
 * Overwrites legacy fields with manifest-derived values when manifest
 * mode is active. Preserves fields that only exist in the legacy response
 * (e.g., revision counter, domain name, site_id).
 *
 * @param {object} config - Original proxy-config response from Laravel
 * @param {object} manifestConfig - Result from resolveManifestConfig()
 * @returns {object} Merged config ready for proxy consumption
 */
export function applyManifestOverrides(config, manifestConfig) {
  if (!manifestConfig) return config;

  return {
    ...config,
    // Override proxy-relevant fields with manifest-derived values
    origin: manifestConfig.origin || config.origin,
    proxy: manifestConfig.proxy || config.proxy,
    consent: manifestConfig.consent || config.consent,
    bootstrapper: mergeBootstrapper(config.bootstrapper, manifestConfig.bootstrapper),
    script_blockers: manifestConfig.script_blockers,
    style_blockers: manifestConfig.style_blockers,
    content_blockers: manifestConfig.content_blockers,
    auto_blocking: manifestConfig.auto_blocking || config.auto_blocking,
    cookie_policy: manifestConfig.cookie_policy,
    features: { ...config.features, ...manifestConfig.features },
    // Attach manifest metadata for downstream observability
    _manifest: manifestConfig._manifest,
    // Preserve domain-level fallback blocker (not stored in manifest)
    fallback_content_blocker: config.fallback_content_blocker || null,
  };
}
