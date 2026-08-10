/**
 * Overlay Merger — Node.js Implementation
 *
 * MUST produce identical merge results as the PHP implementation
 * (OverlayMerger::merge).
 *
 * Merge rules (locked):
 *   1. Only overlay-eligible fields may appear in overlays
 *   2. Overlay keys win over base keys
 *   3. Sequential arrays (lists) are REPLACED entirely
 *   4. Associative objects are deep-merged
 *   5. null values explicitly remove the base key
 *   6. No overlay chaining — base + zero/one overlay only
 *   7. Base-only fields are always preserved from the base
 */

const BASE_ONLY_FIELDS = [
  'site_id',
  'domain',
  'cross_domain_enabled',
  'cross_domains_list',
  'tcf_config',
  'callbacks',
  'consent_version',
];

const OVERLAY_ELIGIBLE_FIELDS = [
  'cookie_groups',
  'content_blockers',
  'script_blockers',
  'style_blockers',
  'auto_blocking',
  'ui_config',
  'translations',
  'geo_rules',
  'features',
];

/**
 * Check if a value is a sequential array (list) vs object.
 */
function isSequentialArray(value) {
  return Array.isArray(value);
}

/**
 * Deep merge two objects. Overlay keys win.
 * Arrays within are replaced entirely. null removes the base key.
 */
function deepMerge(base, overlay) {
  const result = { ...base };

  for (const [key, overlayValue] of Object.entries(overlay)) {
    if (overlayValue === null) {
      delete result[key];
      continue;
    }

    if (
      typeof overlayValue === 'object' &&
      overlayValue !== null &&
      result[key] !== undefined &&
      typeof result[key] === 'object' &&
      result[key] !== null
    ) {
      if (isSequentialArray(overlayValue)) {
        result[key] = overlayValue;
      } else if (!isSequentialArray(result[key])) {
        result[key] = deepMerge(result[key], overlayValue);
      } else {
        result[key] = overlayValue;
      }
    } else {
      result[key] = overlayValue;
    }
  }

  return result;
}

/**
 * Merge a base artifact with zero or one overlay.
 *
 * @param {object} base      The full base artifact
 * @param {object|null} overlay  Sparse overlay (or null for base-only)
 * @returns {object} The merged effective artifact
 */
export function merge(base, overlay) {
  if (!overlay || Object.keys(overlay).length === 0) {
    return { ...base };
  }

  const result = { ...base };

  for (const [key, overlayValue] of Object.entries(overlay)) {
    // Skip overlay_id meta field
    if (key === 'overlay_id') continue;

    // Base-only fields cannot be overridden
    if (BASE_ONLY_FIELDS.includes(key)) continue;

    // Only overlay-eligible fields
    if (!OVERLAY_ELIGIBLE_FIELDS.includes(key)) continue;

    // null = explicit removal
    if (overlayValue === null) {
      delete result[key];
      continue;
    }

    // Both are objects — decide merge strategy
    if (
      typeof overlayValue === 'object' &&
      overlayValue !== null &&
      result[key] !== undefined &&
      typeof result[key] === 'object' &&
      result[key] !== null
    ) {
      if (isSequentialArray(overlayValue)) {
        // List arrays replaced entirely
        result[key] = overlayValue;
      } else if (!isSequentialArray(result[key])) {
        // Associative objects deep-merged
        result[key] = deepMerge(result[key], overlayValue);
      } else {
        result[key] = overlayValue;
      }
    } else {
      result[key] = overlayValue;
    }
  }

  return result;
}

export default { merge, deepMerge, BASE_ONLY_FIELDS, OVERLAY_ELIGIBLE_FIELDS };
