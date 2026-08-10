/**
 * Manifest Canonicalization & Hashing — Node.js Implementation
 *
 * MUST produce identical canonical JSON and SHA-256 hashes
 * as the PHP implementation (ManifestSchema::canonicalize).
 *
 * Rules (locked, identical to PHP):
 *   1. Keys sorted recursively
 *   2. No pretty-print (compact)
 *   3. No trailing newline
 *   4. Unicode unescaped (Node JSON.stringify does this by default)
 *   5. Slashes unescaped (Node JSON.stringify does this by default)
 *   6. Sequential (numeric-indexed) arrays are left in order
 */

import { createHash } from 'node:crypto';

/**
 * Recursively sort object keys for deterministic serialization.
 * Arrays (sequential) are left in order; only object keys are sorted.
 *
 * @param {*} data
 * @returns {*}
 */
export function sortKeysRecursive(data) {
  if (data === null || data === undefined || typeof data !== 'object') {
    return data;
  }

  if (Array.isArray(data)) {
    return data.map(sortKeysRecursive);
  }

  const sorted = {};
  const keys = Object.keys(data).sort();
  for (const key of keys) {
    sorted[key] = sortKeysRecursive(data[key]);
  }
  return sorted;
}

/**
 * Produce canonical JSON — identical to PHP ManifestSchema::canonicalize().
 *
 * @param {object} data
 * @returns {string}
 */
export function canonicalize(data) {
  const sorted = sortKeysRecursive(data);
  return JSON.stringify(sorted);
}

/**
 * SHA-256 hash of canonical JSON representation.
 *
 * @param {object} artifact
 * @returns {string} Hex-encoded SHA-256 hash
 */
export function hashArtifact(artifact) {
  const canonical = canonicalize(artifact);
  return createHash('sha256').update(canonical, 'utf8').digest('hex');
}

export default { canonicalize, hashArtifact, sortKeysRecursive };
