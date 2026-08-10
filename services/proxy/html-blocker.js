/**
 * HTML Blocker — Pure decision layer for script/content blocking.
 *
 * Ports Laravel's ProxyTunnelMiddleware::blockScripts() +
 *   matchesBlocker() + mutateScriptTag() + blockContent()
 * to pure JavaScript functions with no side effects.
 *
 * Architecture rule: this module makes decisions only.
 * It does NOT touch streams, HTTP, or the proxy pipeline.
 */

const AUTO_BLOCKING_DEFAULTS = {
  content: true,
  script: true,
  style: true,
  service: true,
};

function normalizeAutoBlocking(autoBlocking) {
  const candidate = autoBlocking && typeof autoBlocking === 'object'
    ? autoBlocking
    : {};

  return {
    content: candidate.content !== undefined ? !!candidate.content : AUTO_BLOCKING_DEFAULTS.content,
    script: candidate.script !== undefined ? !!candidate.script : AUTO_BLOCKING_DEFAULTS.script,
    style: candidate.style !== undefined ? !!candidate.style : AUTO_BLOCKING_DEFAULTS.style,
    service: candidate.service !== undefined ? !!candidate.service : AUTO_BLOCKING_DEFAULTS.service,
  };
}

function escapeAttr(value) {
  return String(value).replace(/"/g, '&quot;');
}

function isThirdPartyUrl(src, siteHost = null) {
  if (!siteHost || !src) return false;
  try {
    const url = new URL(src.startsWith('//') ? 'https:' + src : src);
    const protocol = url.protocol.toLowerCase();
    if (!['http:', 'https:'].includes(protocol)) return false;

    const resourceHost = url.hostname.replace(/^www\./, '').toLowerCase();
    const cleanSiteHost = siteHost.replace(/^www\./, '').toLowerCase();
    if (!resourceHost || !cleanSiteHost) return false;

    return !(resourceHost === cleanSiteHost || resourceHost.endsWith('.' + cleanSiteHost));
  } catch {
    return false;
  }
}

function providerFromUrl(src) {
  try {
    const url = new URL(src.startsWith('//') ? 'https:' + src : src);
    const host = url.hostname.replace(/^www\./i, '').toLowerCase();
    const parts = host.split('.');
    return parts.length > 2 ? parts.slice(-2).join('.') : host;
  } catch {
    return 'external';
  }
}

/**
 * Check if a script tag's attributes match any blocker rule.
 *
 * Mirrors Laravel's matchesBlocker() exactly:
 * 1. Check handles[] → match against src, id, data-handle attribute values
 * 2. Check phrases[] → match against the full attributes string
 *
 * @param {string} attributes - The raw attributes string from <script ...>
 * @param {object} blocker - { key, service_key, handles: [], phrases: [] }
 * @returns {boolean}
 */
export function matchesBlocker(attributes, blocker) {
  // Handle matching
  if (blocker.handles && blocker.handles.length > 0) {
    for (const rawHandle of blocker.handles) {
      const handle = rawHandle.trim();
      if (!handle) continue;

      // Check src attribute value
      const srcMatch = attributes.match(/src\s*=\s*["']([^"']*)["']/i);
      if (srcMatch) {
        if (srcMatch[1].toLowerCase().includes(handle.toLowerCase())) return true;
      }

      // Check id or data-handle attribute value
      const idMatch = attributes.match(/(?:id|data-handle)\s*=\s*["']([^"']*)["']/i);
      if (idMatch) {
        if (idMatch[1].toLowerCase().includes(handle.toLowerCase())) return true;
      }
    }
  }

  // Phrase matching — search the full attributes string
  if (blocker.phrases && blocker.phrases.length > 0) {
    for (const rawPhrase of blocker.phrases) {
      const phrase = rawPhrase.trim();
      if (!phrase) continue;
      if (attributes.toLowerCase().includes(phrase.toLowerCase())) return true;
    }
  }

  return false;
}

/**
 * Mutate a script tag to prevent execution.
 *
 * Mirrors Laravel's mutateScriptTag() exactly:
 * 1. Strip existing type="..." attribute
 * 2. Append type="text/template" data-ycookies-blocked="true"
 *    data-ycookies-blocker-id="..." data-ycookies-service="..."
 *
 * @param {string} fullTag - The full opening <script ...> tag
 * @param {object} blocker - { key, service_key }
 * @returns {string} Modified tag
 */
export function mutateScriptTag(fullTag, blocker) {
  const serviceKey = blocker.service_key || blocker.service || '';
  const blockerKey = blocker.key || '';

  // Strip existing type attribute (mirrors Laravel's preg_replace)
  let modified = fullTag.replace(/\btype\s*=\s*["'][^"']*["']/i, '');

  // Build insertion attributes
  const requireGroup = blocker.require_group || '';
  const providerKey = blocker.provider_key || '';
  const extraAttrs = [
    `type="text/template"`,
    `data-ycookies-blocked="true"`,
    `data-ycookies-blocker-id="${escapeAttr(blockerKey)}"`,
    `data-ycookies-service="${escapeAttr(serviceKey)}"`,
  ];
  if (requireGroup) extraAttrs.push(`data-ycookies-require-group="${escapeAttr(requireGroup)}"`);
  if (providerKey) extraAttrs.push(`data-ycookies-provider="${escapeAttr(providerKey)}"`);
  const host = blocker._host || blocker.provider_key || '';
  if (host) extraAttrs.push(`data-ycookies-host="${escapeAttr(host)}"`);
  const insertAttrs = ` ${extraAttrs.join(' ')}`;

  // Insert before closing > (mirrors Laravel's preg_replace(/>$/, ...))
  modified = modified.replace(/>$/, insertAttrs + '>');

  return modified;
}

/**
 * Check if a script tag should be blocked and return the decision.
 *
 * @param {string} fullTag - Full opening <script ...> tag
 * @param {string} attributes - Attributes portion of the tag
 * @param {object[]} blockers - Array of { key, service_key, handles[], phrases[] }
 * @returns {{ blocked: boolean, tag: string, blocker?: object }}
 */
export function decideScript(fullTag, attributes, blockers, options = {}) {
  const { siteHost = null, autoBlocking = {}, universalScriptBlocker = null } = options;
  // Self-protection: never block YCookies scripts
  if (attributes.toLowerCase().includes('ycookies')) {
    return { blocked: false, tag: fullTag };
  }

  for (const blocker of blockers) {
    if (matchesBlocker(attributes, blocker)) {
      return {
        blocked: true,
        tag: mutateScriptTag(fullTag, blocker),
        blocker,
      };
    }
  }

  const toggles = normalizeAutoBlocking(autoBlocking);
  if (!toggles.script) {
    return { blocked: false, tag: fullTag };
  }

  const srcMatch = attributes.match(/src\s*=\s*["']([^"']*)["']/i);
  if (srcMatch && isThirdPartyUrl(srcMatch[1], siteHost)) {
    const provider = providerFromUrl(srcMatch[1]);
    const requireGroup = universalScriptBlocker?.require_group || 'marketing';
    const blocker = {
      key: universalScriptBlocker?.key || ('sb-universal-' + provider.replace(/[^a-z0-9]/g, '-')),
      service: '',
      provider_key: provider,
      require_group: requireGroup,
      _host: provider,
      _universal: true,
    };

    return {
      blocked: true,
      tag: mutateScriptTag(fullTag, blocker),
      blocker,
    };
  }

  return { blocked: false, tag: fullTag };
}

/**
 * Build a content-blocked placeholder div (v2 — provider-aware with consent actions).
 *
 * Mirrors the Consent Execution Registry's embed placeholder spec:
 * - Provider-aware title and copy
 * - "Load this content" (instance-level, session-scoped)
 * - "Always allow [Provider]" (provider-level, persisted)
 * - Original iframe base64-encoded for restoration
 * - Unique instance ID for per-embed consent
 *
 * @param {string} fullTag - The full <iframe ...>...</iframe> tag
 * @param {object} blocker - { key, service_key, provider_key, name, hosts }
 * @returns {string} Replacement div HTML
 */
export function buildContentPlaceholder(fullTag, blocker) {
  const serviceKey = blocker.service_key || blocker.service || '';
  const providerKey = blocker.provider_key || serviceKey;
  const providerName = blocker.name || providerKey || 'External Content';
  const encoded = Buffer.from(fullTag).toString('base64');
  
  // Generate a unique instance ID for per-embed consent
  const instanceId = `yc_embed_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
  
  const requireGroup = blocker.require_group || '';
  const requireGroupAttr = requireGroup
    ? ` data-ycookies-require-group="${String(requireGroup).replace(/"/g, '&quot;')}"`
    : '';

  // ── Inject Database Custom HTML if present ──
  if (blocker.html_code && typeof blocker.html_code === 'string' && blocker.html_code.trim() !== '') {
    const customHtml = blocker.html_code.replace(/{{name}}/g, providerName).replace(/{{service_name}}/g, providerName);
    const customCss = blocker.css_code ? `<style>${blocker.css_code}</style>` : '';
    
    return `<div class="ycookies-content-blocker ycookies-embed-placeholder custom-template" ` +
           `data-ycookies-service="${serviceKey}" ` +
           `data-ycookies-provider="${providerKey}" ` +
           `data-ycookies-instance-id="${instanceId}" ` +
           `data-ycookies-original="${encoded}"${requireGroupAttr}>\n` +
           `${customCss}\n${customHtml}\n</div>`;
  }

  // ── Extract original iframe dimensions to preserve layout ──
  // Parse width/height attributes
  const widthMatch = fullTag.match(/\bwidth\s*=\s*["']?(\d+%?)/i);
  const heightMatch = fullTag.match(/\bheight\s*=\s*["']?(\d+%?)/i);
  // Parse inline style for width/height
  const styleMatch = fullTag.match(/\bstyle\s*=\s*["']([^"']+)["']/i);
  const inlineStyle = styleMatch ? styleMatch[1] : '';

  // Build dimension styles — preserve original iframe's space
  let dimensionStyle = '';
  if (widthMatch) {
    const w = widthMatch[1];
    dimensionStyle += `width:${w.includes('%') ? w : w + 'px'};`;
  } else if (inlineStyle.match(/width\s*:/i)) {
    // Width is in inline style, will be inherited below
  } else {
    dimensionStyle += 'width:100%;';
  }
  if (heightMatch) {
    const h = heightMatch[1];
    dimensionStyle += `height:${h.includes('%') ? h : h + 'px'};`;
  } else if (inlineStyle.match(/height\s*:/i)) {
    // Height is in inline style, will be inherited below
  } else {
    dimensionStyle += 'min-height:200px;';
  }

  // Inherit relevant inline styles from the original iframe (margin, aspect-ratio, max-width, etc.)
  const inheritedProps = [];
  for (const prop of ['margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
    'max-width', 'max-height', 'aspect-ratio', 'position', 'top', 'left', 'right', 'bottom',
    'width', 'height']) {
    const propRegex = new RegExp(`${prop}\\s*:\\s*([^;]+)`, 'i');
    const match = inlineStyle.match(propRegex);
    if (match) {
      inheritedProps.push(`${prop}:${match[1].trim()}`);
    }
  }
  if (inheritedProps.length > 0) {
    dimensionStyle += inheritedProps.join(';') + ';';
  }

  // Determine if provider-level consent buttons should show
  const supportsAcceptOnce = blocker.supports_accept_once !== false; // default true
  const supportsAcceptProvider = blocker.supports_accept_provider !== false; // default true

  // Build action buttons HTML
  let actionsHtml = '';
  if (supportsAcceptOnce) {
    actionsHtml += `<button class="yc-embed-btn yc-embed-btn-once" data-action="accept-once" data-instance-id="${instanceId}" style="background:#3b82f6;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;transition:opacity 0.2s;margin:4px;">Load this content</button>`;
  }
  if (supportsAcceptProvider) {
    actionsHtml += `<button class="yc-embed-btn yc-embed-btn-provider" data-action="accept-provider" data-provider-key="${providerKey}" style="background:transparent;color:#94a3b8;border:1px solid #475569;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;transition:opacity 0.2s;margin:4px;">Always allow ${providerName}</button>`;
  }

  return `<div class="ycookies-content-blocker ycookies-embed-placeholder" data-ycookies-service="${serviceKey}" data-ycookies-provider="${providerKey}" data-ycookies-instance-id="${instanceId}" data-ycookies-original="${encoded}"${requireGroupAttr} style="${dimensionStyle}background:#111827;color:#f3f4f6;padding:32px 24px;text-align:center;border-radius:12px;font-family:system-ui,-apple-system,sans-serif;border:1px solid #1f2937;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;box-sizing:border-box;overflow:hidden;"><div style="width:48px;height:48px;border-radius:50%;background:#1e293b;display:flex;align-items:center;justify-content:center;margin-bottom:4px;flex-shrink:0;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/></svg></div><p style="font-weight:600;font-size:16px;margin:0;color:#e2e8f0;">${providerName} content blocked</p><p style="font-size:13px;margin:0;color:#94a3b8;max-width:400px;line-height:1.5;">Loading this content may share data with ${providerName}. Please accept to continue.</p><div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:4px;">${actionsHtml}</div></div>`;
}

/**
 * Check if an iframe's src matches any content blocker host.
 *
 * Mirrors Laravel's blockContent() host matching:
 * - Extract src from attributes
 * - Check if src contains any host from blocker's hosts[]
 * - UNIVERSAL FALLBACK: if siteHost is provided and no known blocker matches,
 *   block any iframe whose src points to a different domain.
 *
 * @param {string} attributes - The iframe attributes string
 * @param {object[]} contentBlockers - Array of { key, service_key, hosts[] }
 * @param {string|null} siteHost - The site's own hostname (for universal blocking)
 * @returns {{ blocked: boolean, blocker?: object }}
 */
export function decideContent(attributes, contentBlockers, siteHost = null, autoBlocking = {}, fallbackBlocker = null) {
  // Extract src
  const srcMatch = attributes.match(/src\s*=\s*["']([^"']*)["']/i);
  if (!srcMatch) {
    return { blocked: false };
  }
  const src = srcMatch[1];

  // 1. Check known blockers first (skip wildcard * hosts — they are universal fallback)
  let wildcardBlocker = null;
  for (const blocker of contentBlockers) {
    const hosts = blocker.hosts || [];
    for (const rawHost of hosts) {
      const host = rawHost.trim();
      if (!host) continue;
      if (host === '*') {
        wildcardBlocker = blocker;
        continue;
      }
      if (src.toLowerCase().includes(host.toLowerCase())) {
        return { blocked: true, blocker };
      }
    }
  }

  // 2. Universal fallback — block any external iframe if siteHost is provided
  if (siteHost && normalizeAutoBlocking(autoBlocking).content) {
    try {
      const iframeUrl = new URL(src.startsWith('//') ? 'https:' + src : src);
      const iframeHost = iframeUrl.hostname.toLowerCase();
      const cleanSiteHost = siteHost.replace(/^www\./, '').toLowerCase();
      const cleanIframeHost = iframeHost.replace(/^www\./, '');

      // Skip same-origin iframes (including subdomains)
      if (cleanIframeHost === cleanSiteHost || cleanIframeHost.endsWith('.' + cleanSiteHost)) {
        return { blocked: false };
      }

      // Skip data:, javascript:, about: URLs
      if (['data:', 'javascript:', 'about:', 'blob:'].some(p => src.startsWith(p))) {
        return { blocked: false };
      }

      // Extract a human-readable provider name from the iframe hostname
      const hostParts = cleanIframeHost.split('.');
      const providerName = hostParts.length > 2
        ? hostParts.slice(-2).join('.')
        : cleanIframeHost;

      // If a domain-level fallback blocker is configured, use it
      // Override name with the detected provider so {{name}} shows "youtube.com" not "Universal Fallback"
      if (fallbackBlocker) {
        return { blocked: true, blocker: { ...fallbackBlocker, name: providerName, provider_key: providerName.replace(/[^a-z0-9.-]/g, '-') } };
      }

      // If a DB-backed wildcard blocker exists, use it instead of synthetic
      if (wildcardBlocker) {
        return { blocked: true, blocker: { ...wildcardBlocker, name: providerName, provider_key: providerName.replace(/[^a-z0-9.-]/g, '-') } };
      }

      // Generate a synthetic universal blocker
      return {
        blocked: true,
        blocker: {
          key: 'cb-universal-' + providerName.replace(/[^a-z0-9]/g, '-'),
          service_key: 'universal',
          provider_key: providerName.replace(/[^a-z0-9.-]/g, '-'),
          name: providerName,
          require_group: 'external_media',
          _universal: true,
        },
      };
    } catch (e) {
      // If URL parsing fails, don't block
      return { blocked: false };
    }
  }

  return { blocked: false };
}

/**
 * Process a full HTML string with script blocking.
 * Uses the same regex as Laravel for parity.
 *
 * @param {string} html - Input HTML
 * @param {object[]} blockers - Script blockers
 * @returns {string} HTML with blocked scripts mutated
 */
export function blockScripts(html, blockers, siteHost = null, autoBlocking = {}, universalScriptBlocker = null) {
  if ((!blockers || blockers.length === 0) && !normalizeAutoBlocking(autoBlocking).script) return html;

  return html.replace(/<script\b([^>]*)>/gi, (fullTag, attributes) => {
    const result = decideScript(fullTag, attributes, blockers || [], { siteHost, autoBlocking, universalScriptBlocker });
    return result.tag;
  });
}

/**
 * Process a full HTML string with content blocking.
 * Uses the same regex as Laravel for parity.
 *
 * @param {string} html - Input HTML
 * @param {object[]} contentBlockers - Content blockers
 * @param {string|null} siteHost - The site's hostname (for universal blocking)
 * @returns {string} HTML with blocked iframes replaced
 */
export function blockContent(html, contentBlockers, siteHost = null, autoBlocking = {}, fallbackBlocker = null) {
  if (!contentBlockers || contentBlockers.length === 0) {
    // Even with no known blockers, universal mode needs siteHost
    if (!siteHost) return html;
  }

  return html.replace(/<iframe\b([^>]*)(?:\/>|>(.*?)<\/iframe>)/gis, (fullTag, attributes) => {
    const result = decideContent(attributes, contentBlockers || [], siteHost, autoBlocking, fallbackBlocker);
    if (result.blocked) {
      return buildContentPlaceholder(fullTag, result.blocker);
    }
    return fullTag;
  });
}

function matchesStyleBlocker(attributes, blocker) {
  const hrefMatch = attributes.match(/href\s*=\s*["']([^"']*)["']/i);
  const href = hrefMatch ? hrefMatch[1] : '';
  const attrsLower = attributes.toLowerCase();

  if (blocker.handles?.length) {
    for (const rawHandle of blocker.handles) {
      const handle = rawHandle.trim().toLowerCase();
      if (!handle) continue;
      if (href.toLowerCase().includes(handle) || attrsLower.includes(handle)) {
        return true;
      }
    }
  }

  if (blocker.phrases?.length) {
    for (const rawPhrase of blocker.phrases) {
      const phrase = rawPhrase.trim().toLowerCase();
      if (!phrase) continue;
      if (attrsLower.includes(phrase)) {
        return true;
      }
    }
  }

  return false;
}

function mutateStyleTag(fullTag, blocker, href) {
  const serviceKey = blocker.service_key || blocker.service || '';
  const blockerKey = blocker.key || '';
  const requireGroup = blocker.require_group || '';
  const providerKey = blocker.provider_key || '';

  let modified = fullTag.replace(/\bhref\s*=\s*["'][^"']*["']/i, '');
  const attrs = [
    `data-ycookies-style-blocked="true"`,
    `data-ycookies-style-href="${escapeAttr(href)}"`,
    `data-ycookies-blocker-id="${escapeAttr(blockerKey)}"`,
    `data-ycookies-service="${escapeAttr(serviceKey)}"`,
  ];
  if (requireGroup) attrs.push(`data-ycookies-require-group="${escapeAttr(requireGroup)}"`);
  if (providerKey) attrs.push(`data-ycookies-provider="${escapeAttr(providerKey)}"`);
  const host = blocker._host || blocker.provider_key || '';
  if (host) attrs.push(`data-ycookies-host="${escapeAttr(host)}"`);

  modified = modified.replace(/>$/, ` ${attrs.join(' ')}>`);
  return modified;
}

export function decideStyle(fullTag, attributes, styleBlockers = [], siteHost = null, autoBlocking = {}, universalStyleBlocker = null) {
  const relMatch = attributes.match(/rel\s*=\s*["']([^"']*)["']/i);
  const rel = (relMatch ? relMatch[1] : '').toLowerCase();
  if (!rel.includes('stylesheet')) {
    return { blocked: false, tag: fullTag };
  }

  const hrefMatch = attributes.match(/href\s*=\s*["']([^"']*)["']/i);
  if (!hrefMatch) {
    return { blocked: false, tag: fullTag };
  }
  const href = hrefMatch[1];

  for (const blocker of styleBlockers) {
    if (matchesStyleBlocker(attributes, blocker)) {
      return {
        blocked: true,
        blocker,
        tag: mutateStyleTag(fullTag, blocker, href),
      };
    }
  }

  const toggles = normalizeAutoBlocking(autoBlocking);
  if (!toggles.style || !isThirdPartyUrl(href, siteHost)) {
    return { blocked: false, tag: fullTag };
  }

  const provider = providerFromUrl(href);
  const requireGroup = universalStyleBlocker?.require_group || 'marketing';
  const blocker = {
    key: universalStyleBlocker?.key || ('stb-universal-' + provider.replace(/[^a-z0-9]/g, '-')),
    service: '',
    provider_key: provider,
    require_group: requireGroup,
    _host: provider,
    _universal: true,
  };

  return {
    blocked: true,
    blocker,
    tag: mutateStyleTag(fullTag, blocker, href),
  };
}

export function blockStyles(html, styleBlockers = [], siteHost = null, autoBlocking = {}, universalStyleBlocker = null) {
  if ((!styleBlockers || styleBlockers.length === 0) && !normalizeAutoBlocking(autoBlocking).style) {
    return html;
  }

  return html.replace(/<link\b([^>]*?)>/gi, (fullTag, attributes) => {
    const result = decideStyle(fullTag, attributes, styleBlockers, siteHost, autoBlocking, universalStyleBlocker);
    return result.tag;
  });
}

/**
 * Apply all blocking to an HTML string.
 * This is the full-buffer equivalent for testing.
 * The streaming version (html-blocker-stream.js) will use decideScript/decideContent directly.
 *
 * @param {string} html - Input HTML
 * @param {object} config - { script_blockers: [], content_blockers: [], site_host?: string }
 * @returns {string} Blocked HTML
 */
export function applyBlocking(html, config) {
  let result = html;
  const scriptBlockers = (config.script_blockers || []).filter(b => (b.blocker_type || 'script') === 'script');
  const styleBlockers = (config.style_blockers || []).concat(
    (config.script_blockers || []).filter(b => (b.blocker_type || 'script') === 'style')
  );
  result = blockScripts(result, scriptBlockers, config.site_host || null, config.auto_blocking || {}, config.universal_script_blocker || null);
  result = blockStyles(result, styleBlockers, config.site_host || null, config.auto_blocking || {}, config.universal_style_blocker || null);
  result = blockContent(result, config.content_blockers || [], config.site_host || null, config.auto_blocking || {}, config.fallback_content_blocker || null);
  return result;
}
