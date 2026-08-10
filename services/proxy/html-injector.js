/**
 * HTML Injector — Phase 1 Transform Stream
 *
 * Injects the YCookies consent bootstrapper <script> before </head>.
 * Uses simple string scanning (indexOf), NOT full parse5 yet.
 *
 * Phase 1 is intentionally minimal:
 * - Injects bootstrapper script only
 * - Does NOT block scripts (Phase 2)
 * - Does NOT rewrite CSP (Phase 2)
 * - Does NOT inject LNA shield (Phase 3)
 *
 * This is a streaming Transform that processes chunks as they arrive.
 * The </head> tag might span two chunks, so we buffer the tail of each chunk.
 *
 * Injection strategy (in priority order):
 *   1. Before </head> — standard injection point
 *   2. Before </body> — fallback for pages with no <head>
 *   3. Append at document end — last resort for HTML fragments
 */

import { Transform } from 'node:stream';
import { generateRumBeaconScript } from './rum-beacon.js';

/**
 * Escapes a string for safe use within an HTML attribute.
 */
function escapeAttr(str) {
  if (str == null) return '';
  return String(str).replace(/"/g, '&quot;');
}

/**
 * Create a Transform stream that injects the consent bootstrapper.
 *
 * @param {object} config - Domain config from Laravel
 * @param {object} [options] - Optional settings
 * @param {string} [options.nonce] - CSP nonce to add to script tag
 * @returns {Transform}
 */
export function createHtmlInjector(config, options = {}) {
  // Legacy `/api/script/{site}.js` is the reliable cross-origin entry (CORS + always 200 when app is up).
  // Static hashed `/build/assets/manager-*.js` can 404 when CDN/app disks diverge; module `error` is not
  // dependable across browsers. So when both exist, inject legacy as the primary `src`.
  const staticUrl = config.bootstrapper?.static_loader_url;
  const legacyUrl = config.bootstrapper?.script_url;
  const apiBase = config.bootstrapper?.api_base || '';
  const siteId = config.site_id || '';

  if (!staticUrl && !legacyUrl) {
    // No bootstrapper configured — pass through unchanged
    return new Transform({
      transform(chunk, encoding, callback) {
        callback(null, chunk);
      },
    });
  }

  const nonceAttr = options.nonce ? ` nonce="${escapeAttr(options.nonce)}"` : '';
  let scriptTag;

  // Prepend standard Google Consent Mode (v2) default denied state inline.
  // This ensures tracking tags (like GTM) that run before manager.js parses
  // still respect the default strict-privacy state.
  const gcmDefaultTag = `
<script${nonceAttr}>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
window.gtag = window.gtag || gtag;
gtag('consent', 'default', {
  'ad_storage': 'denied',
  'ad_user_data': 'denied',
  'ad_personalization': 'denied',
  'analytics_storage': 'denied',
  'functionality_storage': 'denied',
  'personalization_storage': 'denied',
  'security_storage': 'denied',
  'wait_for_update': 500
});
</script>
`;

  const legacyAttrs = [
    legacyUrl ? `src="${escapeAttr(legacyUrl)}"` : '',
    `id="ycookies-manager"`,
    siteId ? `data-ycookies-id="${escapeAttr(siteId)}"` : '',
    apiBase ? `data-ycookies-api="${escapeAttr(apiBase)}"` : '',
    options.gpc ? `data-ycookies-gpc="1"` : '',
    nonceAttr.trim(),
    'type="module"',
    'defer',
  ].filter(Boolean).join(' ');

  // Synchronous inline script that hides already-allowed embed placeholders before
  // the body is rendered, preventing the visible flash that occurs when manager.js
  // (defer + module) restores them later via autoRestoreEmbeds().
  const preRestoreTag = `<script${nonceAttr}>(function(){try{var m=document.cookie.match(/(^|; )ycookies_providers=([^;]+)/);if(!m)return;var ps=JSON.parse(decodeURIComponent(m[2]));if(!Array.isArray(ps)||!ps.length)return;var css=ps.map(function(p){var safe=p.replace(/[\\\\<>"']/g,'');return '.ycookies-embed-placeholder[data-ycookies-provider="'+safe+'"]{display:none!important}'}).join('');var s=document.createElement('style');s.id='ycookies-prerestore';s.textContent=css;document.head.appendChild(s);}catch(e){}})();</script>\n`;

  if (legacyUrl) {
    scriptTag = `${gcmDefaultTag}${preRestoreTag}<script ${legacyAttrs}></script>\n`;
  } else if (staticUrl) {
    // Static-only (no legacy URL in config): best-effort immutable loader + optional nothing to fall back to
    const attrs = [
      `src="${escapeAttr(staticUrl)}"`,
      `id="ycookies-manager"`,
      siteId ? `data-ycookies-id="${escapeAttr(siteId)}"` : '',
      apiBase ? `data-ycookies-api="${escapeAttr(apiBase)}"` : '',
      options.gpc ? `data-ycookies-gpc="1"` : '',
      nonceAttr.trim(),
      'defer',
    ].filter(Boolean).join(' ');
    scriptTag = `${gcmDefaultTag}${preRestoreTag}<script ${attrs} type="module"></script>\n`;
  }

  // RUM beacon — inline snippet, no external fetch
  const beaconUrl = apiBase ? `${apiBase}/api/rum/beacon` : '/api/rum/beacon';
  const rumTag = generateRumBeaconScript(beaconUrl, { nonce: options.nonce });
  scriptTag += rumTag;
  let injected = false;
  // Buffer tail of each chunk to handle </head> or </body> spanning chunks.
  // 20 chars covers </head> (7) or </body> (7) plus whitespace/attribute variations.
  const TAIL_SIZE = 20;
  let tailBuffer = '';

  const stream = new Transform({
    transform(chunk, encoding, callback) {
      if (injected) {
        // Already injected — pass through
        callback(null, chunk);
        return;
      }

      try {
        // Combine tail from previous chunk with current chunk
        const text = tailBuffer + chunk.toString('utf8');
        tailBuffer = '';

        // Duplicate detection: skip injection if page already has YCookies
        const lowerText = text.toLowerCase();
        if (
          lowerText.includes('id="ycookies-manager"') ||
          lowerText.includes("id='ycookies-manager'") ||
          lowerText.includes('data-ycookies-id') ||
          lowerText.includes('window.ycookies')
        ) {
          injected = true; // Mark as done so we stop scanning
          stream.injectionPath = 'skip_duplicate';
          callback(null, Buffer.from(text, 'utf8'));
          return;
        }

        // Look for </head> (case-insensitive) — primary injection point
        const headCloseIdx = text.toLowerCase().indexOf('</head');

        if (headCloseIdx !== -1) {
          // Found </head> — inject before it
          const before = text.slice(0, headCloseIdx);
          const after = text.slice(headCloseIdx);
          injected = true;
          stream.injectionPath = 'head';
          callback(null, Buffer.from(before + scriptTag + after, 'utf8'));
          return;
        }

        // Look for </body> (case-insensitive) — secondary injection point
        const bodyCloseIdx = text.toLowerCase().indexOf('</body');

        if (bodyCloseIdx !== -1) {
          const before = text.slice(0, bodyCloseIdx);
          const after = text.slice(bodyCloseIdx);
          injected = true;
          stream.injectionPath = 'body';
          callback(null, Buffer.from(before + scriptTag + after, 'utf8'));
          return;
        }

        // Neither found yet. Buffer tail chars in case tag spans chunks.
        if (text.length > TAIL_SIZE) {
          tailBuffer = text.slice(-TAIL_SIZE);
          callback(null, Buffer.from(text.slice(0, -TAIL_SIZE), 'utf8'));
        } else {
          tailBuffer = text;
          callback(null, Buffer.alloc(0));
        }
      } catch (err) {
        // ── Mutation error isolation ─────────────────────────────
        // If the transform throws (corrupt encoding, unexpected buffer state),
        // pass the raw chunk through unchanged so the page still loads.
        // The circuit breaker will track this via the injectionError flag.
        stream.injectionError = err;
        injected = true; // Stop attempting further injection
        stream.injectionPath = 'error_fallback';
        callback(null, chunk);
      }
    },

    flush(callback) {
      if (!tailBuffer && injected) {
        callback();
        return;
      }

      try {
        // Flush remaining tail buffer
        if (injected) {
          // Already injected — just flush the leftover
          callback(null, Buffer.from(tailBuffer, 'utf8'));
        } else {
          // Never found </head> in main chunks — check tail buffer
          const lowerTail = tailBuffer.toLowerCase();
          const headIdx = lowerTail.indexOf('</head');

          if (headIdx !== -1) {
            const before = tailBuffer.slice(0, headIdx);
            const after = tailBuffer.slice(headIdx);
            stream.injectionPath = 'flush_head';
            callback(null, Buffer.from(before + scriptTag + after, 'utf8'));
          } else {
            // Check for </body> as secondary target
            const bodyIdx = lowerTail.indexOf('</body');
            if (bodyIdx !== -1) {
              const before = tailBuffer.slice(0, bodyIdx);
              const after = tailBuffer.slice(bodyIdx);
              stream.injectionPath = 'flush_body';
              callback(null, Buffer.from(before + scriptTag + after, 'utf8'));
            } else {
              // Last resort: append at end of document (no </head> or </body> found)
              stream.injectionPath = 'flush_append';
              callback(null, Buffer.from(tailBuffer + scriptTag, 'utf8'));
            }
          }
        }
      } catch (err) {
        // Flush error isolation — pass through whatever tail buffer remains
        stream.injectionError = err;
        stream.injectionPath = 'error_fallback';
        callback(null, tailBuffer ? Buffer.from(tailBuffer, 'utf8') : Buffer.alloc(0));
      }
      tailBuffer = '';
    },
  });

  // Expose injection tracking — readable after stream ends
  stream.injectionPath = 'none';  // default until injection happens
  return stream;
}
