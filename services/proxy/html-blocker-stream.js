/**
 * HTML Blocker Stream — parse5 SAX streaming transform for script/content blocking.
 *
 * Architecture:
 *   html-blocker.js = decision oracle (pure functions)
 *   html-blocker-stream.js = streaming transport (this file)
 *
 * The stream does NOT re-implement blocking logic. It delegates all
 * decisions to the pure functions in html-blocker.js.
 *
 * State machine:
 *   NORMAL → emit tokens as-is, check script/iframe tags against blocker
 *   SUPPRESSING_IFRAME → swallow all tokens until matching </iframe>
 *
 * Key behaviors:
 *   - Script tags: mutate opening tag attrs, pass body + closing tag through
 *   - Iframes: replace entire element (opening + body + closing) with placeholder div
 *   - All other content: pass through unchanged
 *   - No full-body buffering
 */

import { SAXParser } from 'parse5-sax-parser';
import { Transform } from 'node:stream';
import { decideScript, decideContent, decideStyle, buildContentPlaceholder } from './html-blocker.js';

/**
 * Serialize attributes back to HTML string.
 * @param {Array<{name: string, value: string}>} attrs
 * @returns {string}
 */
function serializeAttrs(attrs) {
  if (!attrs || attrs.length === 0) return '';
  return attrs.map(a => {
    if (a.value === '') return ` ${a.name}`;
    return ` ${a.name}="${a.value}"`;
  }).join('');
}

/**
 * Reconstruct the full opening tag string from SAX event data.
 * @param {string} tagName
 * @param {Array} attrs
 * @param {boolean} selfClosing
 * @returns {string}
 */
function reconstructTag(tagName, attrs, selfClosing) {
  return `<${tagName}${serializeAttrs(attrs)}${selfClosing ? ' /' : ''}>`;
}

/**
 * Create a streaming HTML blocker transform.
 *
 * @param {object} config - { script_blockers: [], content_blockers: [] }
 * @returns {Transform} Node.js Transform stream
 */
export function createBlockerStream(config) {
  const sax = new SAXParser();

  // State machine
  let state = 'NORMAL';          // 'NORMAL' or 'SUPPRESSING_IFRAME'
  let suppressDepth = 0;         // Nested iframe depth tracking
  let iframeCollector = '';      // Collect full iframe tag for base64 encoding
  let iframeBlocker = null;      // The blocker that matched the iframe

  const scriptBlockers = config.script_blockers || [];
  const styleBlockers = (config.style_blockers || []).concat(
    (config.script_blockers || []).filter(b => (b.blocker_type || 'script') === 'style')
  );
  const contentBlockers = config.content_blockers || [];
  const siteHost = config.site_host || null;
  const autoBlocking = config.auto_blocking || {};
  const fallbackBlocker = config.fallback_content_blocker || null;
  const universalScriptBlocker = config.universal_script_blocker || null;
  const universalStyleBlocker = config.universal_style_blocker || null;

  const tx = new Transform({
    transform(chunk, encoding, callback) {
      sax.write(chunk.toString());
      callback();
    },
    flush(callback) {
      sax.end();
      callback();
    },
  });

  // ── SAX Events ──────────────────────────────────────────

  sax.on('startTag', (tag) => {
    const { tagName, attrs, selfClosing } = tag;
    const lowerTag = tagName.toLowerCase();

    // ── SUPPRESSING_IFRAME state ──
    if (state === 'SUPPRESSING_IFRAME') {
      // Track nested tags (could be nested iframes)
      if (lowerTag === 'iframe') {
        suppressDepth++;
      }
      // Collect the raw HTML for base64 encoding
      iframeCollector += reconstructTag(tagName, attrs, selfClosing);
      return; // Swallow
    }

    // ── NORMAL state ──

    // Check script tags
    if (lowerTag === 'script') {
      // Script is never a void element — ignore selfClosing flag
      const rawAttrs = serializeAttrs(attrs).trim();
      const fullTag = `<${tagName}${serializeAttrs(attrs)}>`;
      const result = decideScript(fullTag, rawAttrs, scriptBlockers, { siteHost, autoBlocking, universalScriptBlocker });

      if (result.blocked) {
        // Emit the mutated tag (with type=text/template + data attrs)
        tx.push(result.tag);
      } else {
        tx.push(fullTag);
      }
      return;
    }

    if (lowerTag === 'link') {
      const rawAttrs = serializeAttrs(attrs).trim();
      const fullTag = reconstructTag(tagName, attrs, selfClosing);
      const result = decideStyle(fullTag, rawAttrs, styleBlockers, siteHost, autoBlocking, universalStyleBlocker);
      tx.push(result.tag);
      return;
    }

    // Check iframe tags for content blocking
    if (lowerTag === 'iframe') {
      const rawAttrs = serializeAttrs(attrs).trim();
      const result = decideContent(rawAttrs, contentBlockers, siteHost, autoBlocking, fallbackBlocker);

      if (result.blocked) {
        if (selfClosing) {
          // Self-closing iframe — emit placeholder immediately
          const fullTag = reconstructTag(tagName, attrs, true);
          tx.push(buildContentPlaceholder(fullTag, result.blocker));
        } else {
          // Start suppressing — need to collect until </iframe>
          state = 'SUPPRESSING_IFRAME';
          suppressDepth = 1;
          iframeCollector = reconstructTag(tagName, attrs, false);
          iframeBlocker = result.blocker;
        }
        return;
      }
    }

    // Default: pass through
    tx.push(reconstructTag(tagName, attrs, selfClosing));
  });

  sax.on('endTag', (tag) => {
    const lowerTag = tag.tagName.toLowerCase();

    if (state === 'SUPPRESSING_IFRAME') {
      if (lowerTag === 'iframe') {
        suppressDepth--;
        if (suppressDepth === 0) {
          // End of blocked iframe — emit placeholder with collected content
          iframeCollector += `</${tag.tagName}>`;
          tx.push(buildContentPlaceholder(iframeCollector, iframeBlocker));

          // Reset state
          state = 'NORMAL';
          iframeCollector = '';
          iframeBlocker = null;
          return;
        }
      }
      // Still suppressing — collect
      iframeCollector += `</${tag.tagName}>`;
      return;
    }

    // Normal: pass through
    tx.push(`</${tag.tagName}>`);
  });

  sax.on('text', (token) => {
    if (state === 'SUPPRESSING_IFRAME') {
      iframeCollector += token.text;
      return;
    }
    tx.push(token.text);
  });

  sax.on('comment', (token) => {
    if (state === 'SUPPRESSING_IFRAME') {
      iframeCollector += `<!--${token.text}-->`;
      return;
    }
    tx.push(`<!--${token.text}-->`);
  });

  sax.on('doctype', (token) => {
    // Reconstruct doctype
    let doctypeStr = '<!DOCTYPE';
    if (token.name) doctypeStr += ` ${token.name}`;
    if (token.publicId) doctypeStr += ` PUBLIC "${token.publicId}"`;
    if (token.systemId) doctypeStr += ` "${token.systemId}"`;
    doctypeStr += '>';
    tx.push(doctypeStr);
  });

  return tx;
}

/**
 * Process a full HTML string through the streaming blocker.
 * This is for testing only — production uses the stream directly.
 *
 * @param {string} html - Input HTML
 * @param {object} config - { script_blockers: [], content_blockers: [] }
 * @returns {Promise<string>} Blocked HTML
 */
export function streamBlock(html, config) {
  return new Promise((resolve, reject) => {
    const stream = createBlockerStream(config);
    const chunks = [];

    stream.on('data', (chunk) => chunks.push(chunk));
    stream.on('end', () => resolve(chunks.join('')));
    stream.on('error', reject);

    stream.write(html);
    stream.end();
  });
}
