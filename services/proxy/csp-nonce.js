/**
 * CSP Nonce — Minimal Content Security Policy nonce support.
 *
 * Purpose: allow YCookies' injected bootstrapper script to execute
 * on sites that enforce script-src via CSP.
 *
 * Design rules:
 * - Generate one nonce per HTML response
 * - Add our nonce to script-src (or script-src-elem)
 * - NEVER weaken existing policy (don't add unsafe-inline, etc.)
 * - If no CSP exists on the response, do NOT add one
 * - If strict-dynamic is present, just add the nonce (strict-dynamic
 *   already allows scripts loaded from trusted nonces)
 * - Conservative merge only — do not rewrite the entire CSP
 */

import { randomBytes } from 'node:crypto';

/**
 * Generate a cryptographically random base64-encoded nonce.
 * @returns {string} 16-byte random nonce, base64-encoded
 */
export function generateNonce() {
  return randomBytes(16).toString('base64');
}

/**
 * Parse a CSP header string into a Map of directive → values.
 *
 * @param {string} csp - Raw CSP header value
 * @returns {Map<string, string>} directive name (lowercase) → directive value string
 */
export function parseCSP(csp) {
  const directives = new Map();
  if (!csp) return directives;

  for (const part of csp.split(';')) {
    const trimmed = part.trim();
    if (!trimmed) continue;

    const spaceIdx = trimmed.indexOf(' ');
    if (spaceIdx === -1) {
      // Directive with no value (e.g., "upgrade-insecure-requests")
      directives.set(trimmed.toLowerCase(), '');
    } else {
      const name = trimmed.slice(0, spaceIdx).toLowerCase();
      const value = trimmed.slice(spaceIdx + 1).trim();
      directives.set(name, value);
    }
  }

  return directives;
}

/**
 * Serialize a CSP directive Map back to a header string.
 *
 * @param {Map<string, string>} directives
 * @returns {string}
 */
export function serializeCSP(directives) {
  const parts = [];
  for (const [name, value] of directives) {
    parts.push(value ? `${name} ${value}` : name);
  }
  return parts.join('; ');
}

/**
 * Merge our nonce into an existing CSP header.
 *
 * Strategy:
 * 1. If script-src-elem exists → add nonce there (it overrides script-src for scripts)
 * 2. Else if script-src exists → add nonce there
 * 3. Else if default-src exists → create script-src from default-src values + nonce
 *    (so we don't weaken default-src for other resource types)
 * 4. If none of the above exist, the CSP doesn't restrict scripts → no change needed
 *
 * @param {string} cspHeader - Raw CSP header value
 * @param {string} nonce - The nonce value (without 'nonce-' prefix)
 * @returns {{ csp: string, modified: boolean }}
 */
export function mergeNonce(cspHeader, nonce) {
  if (!cspHeader || !nonce) {
    return { csp: cspHeader || '', modified: false };
  }

  const directives = parseCSP(cspHeader);
  const nonceToken = `'nonce-${nonce}'`;

  // Check if our nonce is already there (shouldn't happen, but be safe)
  const allValues = [...directives.values()].join(' ');
  if (allValues.includes(nonceToken)) {
    return { csp: cspHeader, modified: false };
  }

  // Strategy 1: script-src-elem takes priority
  if (directives.has('script-src-elem')) {
    const current = directives.get('script-src-elem');
    directives.set('script-src-elem', `${current} ${nonceToken}`.trim());
    return { csp: serializeCSP(directives), modified: true };
  }

  // Strategy 2: script-src
  if (directives.has('script-src')) {
    const current = directives.get('script-src');
    directives.set('script-src', `${current} ${nonceToken}`.trim());
    return { csp: serializeCSP(directives), modified: true };
  }

  // Strategy 3: default-src → create script-src with default-src values + nonce
  if (directives.has('default-src')) {
    const defaultSrc = directives.get('default-src');
    // Insert script-src right after default-src in the map
    const newDirectives = new Map();
    for (const [name, value] of directives) {
      newDirectives.set(name, value);
      if (name === 'default-src') {
        newDirectives.set('script-src', `${defaultSrc} ${nonceToken}`.trim());
      }
    }
    return { csp: serializeCSP(newDirectives), modified: true };
  }

  // No script restriction in CSP → don't add one
  return { csp: cspHeader, modified: false };
}

/**
 * Merge a connect-src origin into an existing CSP header.
 *
 * Required for static loader mode: the browser fetches config from
 * cookies.ypsilon.dev cross-origin, which connect-src must allow.
 *
 * Strategy:
 * 1. If connect-src exists → add origin if not already present
 * 2. Else if default-src exists → create connect-src from default-src + origin
 * 3. If neither exists → CSP doesn't restrict connects → no change
 *
 * @param {string} cspHeader - Raw CSP header value
 * @param {string} origin - Origin URL to allow (e.g., "https://cookies.ypsilon.dev")
 * @returns {{ csp: string, modified: boolean }}
 */
export function mergeConnectSrc(cspHeader, origin) {
  if (!cspHeader || !origin) {
    return { csp: cspHeader || '', modified: false };
  }

  const directives = parseCSP(cspHeader);

  // Already allowed?
  const connectSrc = directives.get('connect-src') || '';
  if (connectSrc.includes(origin)) {
    return { csp: cspHeader, modified: false };
  }

  // Strategy 1: connect-src exists → append
  if (directives.has('connect-src')) {
    directives.set('connect-src', `${connectSrc} ${origin}`.trim());
    return { csp: serializeCSP(directives), modified: true };
  }

  // Strategy 2: default-src → create connect-src
  if (directives.has('default-src')) {
    const defaultSrc = directives.get('default-src');
    const newDirectives = new Map();
    for (const [name, value] of directives) {
      newDirectives.set(name, value);
      if (name === 'default-src') {
        newDirectives.set('connect-src', `${defaultSrc} ${origin}`.trim());
      }
    }
    return { csp: serializeCSP(newDirectives), modified: true };
  }

  // No connect restriction → don't add one
  return { csp: cspHeader, modified: false };
}

/**
 * Build a script tag with nonce attribute.
 *
 * @param {string} scriptUrl - URL for the script src
 * @param {string} nonce - Nonce value
 * @returns {string} Script tag HTML
 */
export function buildNoncedScriptTag(scriptUrl, nonce) {
  return `<script src="${scriptUrl}" id="ycookies-manager" nonce="${nonce}" defer></script>\n`;
}
