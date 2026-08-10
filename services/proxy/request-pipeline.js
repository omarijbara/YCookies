import { randomUUID } from 'node:crypto';
import { request as undiciRequest } from 'undici';
import { getDomainConfig } from './config-resolver.js';
import { applyDecompressor } from './decompressor.js';
import { sendProxyError, PROXY_ERRORS } from './error-responses.js';
import { generateNonce, mergeNonce, mergeConnectSrc } from './csp-nonce.js';
import { createNonceReplaceStream } from './csp-replace-stream.js';
import { filterRequestHeaders, filterResponseHeaders, HOP_BY_HOP } from './headers.js';
import { filterCookies } from './cookie-filter.js';
import { assertPublicIP, assertPublicUrl, assertPublicHostname } from './ssrf.js';
import { evaluateRequestEligibility, evaluateEligibility, recordDecision, setCacheHeaders } from './response-cache.js';
import { getCache, setCache } from './cache-store.js';
import { recordMetric } from './metrics.js';
import { inc } from './proxy-counters.js';
import { shouldTransform, recordSuccess, recordFailure } from './circuit-breaker.js';
import { createHtmlInjector } from './html-injector.js';
import { createBlockerStream } from './html-blocker-stream.js';
import { getOrCreateAgent, destroyAgent } from './agent-pool.js';
import { enforceDomainRateLimit } from './rate-limit-engine.js';
import { normalizeAutoBlockingConfig, selectOriginAuthToken } from './proxy-utils.js';
import { resolveManifestConfig, applyManifestOverrides } from './manifest-consumer.js';
import { triggerSwrRevalidation } from './swr-revalidator.js';

export function buildProxyHandler(checkDomainRateLimit, fastify, { REVALIDATE_SECRET, UPSTREAM_REQUEST_TIMEOUT_MS }) {
  return async (request, reply) => {
  const requestId = randomUUID();
  const startTime = Date.now();
  const hostname = request.hostname;
  
  if (request.headers['upgrade'] === 'websocket') {
      request.log.info({ requestId, url: request.raw.url }, 'proxyHandler caught a websocket upgrade! wsHandler is being ignored!');
  }

  // Force HTTP -> HTTPS redirect based on load balancer headers
  const proto = request.headers['x-forwarded-proto'] || request.protocol;
  if (proto === 'http') {
    return reply.redirect(`https://${hostname}${request.raw.url}`, 301);
  }

  // ─── Stage Timing ─────────────────────────────────────────────
  // Per-request timing markers for observability.
  // All values are wall-clock ms relative to startTime.
  const timing = {
    config_ms:    0,   // getDomainConfig() wall time
    ssrf_ms:      0,   // SSRF + DNS validation
    origin_ttfb_ms: 0, // upstream request start → headers received
    origin_body_ms: 0, // headers received → body complete
    transform_ms: 0,   // injector + blocker pipeline
    total_ms:     0,   // end-to-end
  };

  // 1. Fail-closed host lookup
  const configStart = Date.now();
  let config;
  try {
    config = await getDomainConfig(hostname);
  } catch (err) {
    request.log.error({ requestId, hostname, err: err.message }, 'Config lookup failed');
    return sendProxyError(reply, request, PROXY_ERRORS.CONFIG_FETCH_FAILED, 'Service Unavailable');
  }
  timing.config_ms = Date.now() - configStart;

  if (!config) {
    return sendProxyError(reply, request, PROXY_ERRORS.DOMAIN_NOT_CONFIGURED, 'Service Unavailable');
  }

  // 1b. Apply manifest overrides if manifest mode is active for this domain.
  // This replaces legacy DB-composed fields (origin, blockers, etc.) with
  // immutable manifest-derived values. Falls back silently to legacy if
  // manifest is disabled or verification fails.
  const manifestProjection = resolveManifestConfig(config);
  if (manifestProjection) {
    config = applyManifestOverrides(config, manifestProjection);
    request.log.debug({ requestId, hostname, rev: manifestProjection._manifest?.revision }, 'manifest mode active');
  }

  config.auto_blocking = normalizeAutoBlockingConfig(config.auto_blocking);

  const urlPath = new URL(request.raw.url, `https://${hostname}`).pathname;
  const rateLimitAllowed = await enforceDomainRateLimit(request, reply, config, urlPath, checkDomainRateLimit);
  if (!rateLimitAllowed) {
    return;
  }

  // When origin has an IP, we connect to the hostname (for correct Host/SNI)
  // but use Undici's connect option to resolve to the actual IP.
  // This mirrors Laravel's CURLOPT_RESOLVE behavior.
  const originHost = config.origin.host || hostname;
  const upstreamUrl = config.origin.url
    ? `${config.origin.url.replace(/\/$/, '')}${request.raw.url}`
    : `https://${originHost}${request.raw.url}`;

  // 2b. SSRF protection — validate origin is not private
  const ssrfStart = Date.now();
  try {
    if (config.origin.subdomain) {
      assertPublicUrl(`https://${config.origin.subdomain}`);
    } else if (config.origin.ip) {
      assertPublicIP(config.origin.ip);
    } else {
      assertPublicUrl(upstreamUrl);
    }
  } catch (err) {
    request.log.warn({ requestId, hostname, err: err.message }, 'SSRF blocked');
    inc('ssrf_ip_blocked');
    return sendProxyError(reply, request, PROXY_ERRORS.ORIGIN_UNREACHABLE, 'Configuration Error');
  }

  // 2c. DNS resolution SSRF check — defense-in-depth against DNS rebinding
  try {
    const resolveHost = config.origin.subdomain || config.origin.host || hostname;
    await assertPublicHostname(resolveHost);
  } catch (err) {
    request.log.warn({ requestId, hostname, err: err.message }, 'SSRF DNS rebind blocked');
    inc('ssrf_dns_blocked');
    return sendProxyError(reply, request, PROXY_ERRORS.SSRF_BLOCKED, 'Configuration Error');
  }
  timing.ssrf_ms = Date.now() - ssrfStart;

  // 3. Prepare upstream request
  const forwardHeaders = filterRequestHeaders(request.headers, request.ip);

  // Override Host header to match upstream expectation
  forwardHeaders.host = originHost;
  
  // Send the domain's origin auth token. During rotation grace periods,
  // selectOriginAuthToken() returns the legacy token until it expires.
  const originToken = selectOriginAuthToken(config.origin);
  if (originToken) {
    forwardHeaders['x-ycookies-origin-auth'] = originToken;
    const isLegacy = originToken === config.origin.auth_token_legacy;
    if (isLegacy) {
      inc('origin_auth_legacy');
      request.log.debug({ requestId, hostname }, 'origin auth: using legacy token (grace period)');
    } else {
      inc('origin_auth_current');
    }
  } else {
    inc('origin_auth_none');
  }

  // SWR: Drop internal secret before reaching Upstream Origin
  const isRevalidation = request.headers['x-yc-revalidate-secret'] === REVALIDATE_SECRET;
  delete forwardHeaders['x-yc-revalidate-secret'];

  // 3a. Cache Read Path (Edge Hit)
  const reqCheck = evaluateRequestEligibility({
    hostname,
    method: request.method,
    url: request.raw.url,
    path: urlPath,
    requestHeaders: request.headers,
    config,
  });

  if (reqCheck.eligible && !isRevalidation) {
    const cachedItem = await getCache(reqCheck.cacheKey);
    if (cachedItem) {
      
      const cachedHeaders = { ...cachedItem.headers };
      let fromCacheState = 'hit';
      
      // SWR Evaluation
      const staleAt = parseInt(cachedHeaders['x-yc-stale-at'] || '0', 10);
      delete cachedHeaders['x-yc-stale-at']; // Never leak internal TTL boundaries to clients
      
      if (staleAt > 0 && Date.now() > staleAt) {
        fromCacheState = 'stale';
        inc('cache_stale');
        request.log.info({ requestId, hostname, cache_key: reqCheck.cacheKey }, 'cache stale hit (triggering SWR)');
        
        // SWR: Direct Native Fastify Background Loopback
        triggerSwrRevalidation(fastify, request, REVALIDATE_SECRET);
      } else {
        inc('cache_hit');
        request.log.info({ requestId, hostname, cache_key: reqCheck.cacheKey }, 'cache hit');
      }

      cachedHeaders['x-yc-cache'] = fromCacheState;
      cachedHeaders['x-request-id'] = requestId;

      // Ensure fresh nonce generation for CSP
      const nonce = generateNonce();
      const existingCSP = cachedHeaders['content-security-policy'];
      if (existingCSP) {
        const merged = mergeNonce(existingCSP, nonce);
        if (merged.modified) cachedHeaders['content-security-policy'] = merged.csp;
      }
      
      const existingCSPRO = cachedHeaders['content-security-policy-report-only'];
      if (existingCSPRO) {
        const mergedRO = mergeNonce(existingCSPRO, nonce);
        if (mergedRO.modified) cachedHeaders['content-security-policy-report-only'] = mergedRO.csp;
      }

      reply.headers(cachedHeaders);
      reply.code(200);

      timing.total_ms = Date.now() - startTime;
      recordMetric({
        domain: hostname,
        method: request.method,
        status: 200,
        duration_ms: timing.total_ms,
        origin_ttfb_ms: 0,
        from_cache: 'hit',
        response_type: 'html',
        html_injected: true,
        blocked_scripts: 0, // already blocked during cache writing
        blocked_content: 0,
        filtered_cookies: 0,
        csp_nonce_added: !!cachedHeaders['content-security-policy'],
        bytes_in: parseInt(request.headers['content-length'] || '0', 10),
        bytes_out: cachedItem.body.length,
        error_code: null,
        config_version: config.revision || 0,
      });

      const { Readable } = await import('node:stream');
      const NONCE_TOKEN = '__YCOOKIES_NONCE_TOKEN__';
      const nonceReplacer = createNonceReplaceStream(NONCE_TOKEN, nonce);
      return reply.send(Readable.from(cachedItem.body).pipe(nonceReplacer));
    }
  }

  // Build Undici request options for IP-pinned origins
  let actualUrl = upstreamUrl;
  let upstreamTimedOut = false;
  let cleanupUpstreamGuards = () => {};
  const requestOptions = {
    method: request.method,
    headers: forwardHeaders,
    body: ['GET', 'HEAD'].includes(request.method) ? undefined : request.body,
    maxRedirections: 0,       // Don't follow redirects (SSRF protection)
    headersTimeout: 30000,
    bodyTimeout: 60000,
  };

  if (config.origin.subdomain) {
    actualUrl = `https://${config.origin.subdomain}${request.raw.url}`;
  } else if (config.origin.ip) {
    actualUrl = `https://${config.origin.ip}${request.raw.url}`;
    requestOptions.dispatcher = getOrCreateAgent(config.origin.ip, originHost);
  }

  const upstreamAbort = new AbortController();
  const abortUpstream = (reason) => {
    if (!upstreamAbort.signal.aborted) {
      upstreamAbort.abort(reason);
    }
  };
  const onClientDisconnect = () => {
    if (!reply.raw.writableEnded) {
      abortUpstream(new Error('client disconnected before upstream response completed'));
    }
  };
  const overallTimeout = setTimeout(() => {
    upstreamTimedOut = true;
    abortUpstream(new Error(`upstream request exceeded ${UPSTREAM_REQUEST_TIMEOUT_MS}ms`));
  }, UPSTREAM_REQUEST_TIMEOUT_MS);
  overallTimeout.unref?.();
  let upstreamGuardsCleaned = false;
  cleanupUpstreamGuards = () => {
    if (upstreamGuardsCleaned) return;
    upstreamGuardsCleaned = true;
    clearTimeout(overallTimeout);
    request.raw.off('aborted', onClientDisconnect);
    reply.raw.off('close', onClientDisconnect);
  };
  request.raw.on('aborted', onClientDisconnect);
  reply.raw.on('close', onClientDisconnect);
  requestOptions.signal = upstreamAbort.signal;

  const originFetchStart = Date.now();
  try {
    const upstream = await undiciRequest(actualUrl, requestOptions);
    timing.origin_ttfb_ms = Date.now() - originFetchStart;

    const contentType = (upstream.headers['content-type'] || '').toLowerCase();
    const isHtml = contentType.includes('text/html') && upstream.statusCode >= 200 && upstream.statusCode < 400;

    // 4. Build response headers
    const responseHeaders = filterResponseHeaders(upstream.headers);
    responseHeaders['x-request-id'] = requestId;
    responseHeaders['x-proxy'] = 'ycookies';

    // 4b. Cookie filtering — apply domain policy
    const rawSetCookies = upstream.headers['set-cookie'];
    if (rawSetCookies) {
      const cookieResult = filterCookies(rawSetCookies, config.cookie_policy);
      if (cookieResult.allowed.length > 0) {
        responseHeaders['set-cookie'] = cookieResult.allowed;
      }
      // Log blocked cookies for debugging
      if (cookieResult.blocked.length > 0) {
        request.log.info({
          requestId,
          hostname,
          blocked: cookieResult.blocked,
        }, 'cookies filtered');
      }
    }

    // Strip content-length for HTML responses because injection changes body size
    // Generate CSP nonce for injected scripts (local variable — no temp headers)
    let nonce = null;
    if (isHtml) {
      delete responseHeaders['content-length'];
      nonce = generateNonce();
    }

    const cacheResult = evaluateEligibility({
      hostname,
      method: request.method,
      url: request.raw.url,
      path: urlPath,
      requestHeaders: request.headers,
      statusCode: upstream.statusCode,
      responseHeaders,
      rawUpstreamHeaders: upstream.headers,
      config,
    });
    recordDecision(cacheResult);
    
    if (cacheResult.eligible) {
      inc('cache_miss');
    } else {
      inc('cache_bypass');
    }
    
    setCacheHeaders(responseHeaders, cacheResult);
    request.log.info({
      requestId,
      hostname,
      cache_key: cacheResult.cacheKey,
      eligible: cacheResult.eligible,
      reason: cacheResult.reason || 'none',
      status: upstream.statusCode,
      content_type: (upstream.headers['content-type'] || '').split(';')[0],
      has_set_cookie: !!upstream.headers['set-cookie'],
    }, 'cache policy decision');

    let cacheHeadersCopy = null;
    if (cacheResult.eligible) {
      cacheHeadersCopy = { ...responseHeaders };
      // Prevent Session Fixation by explicitly stripping cookies from the cache
      delete cacheHeadersCopy['set-cookie'];
    }

    if (isHtml && nonce) {
      // Merge nonce into CSP header if one exists (never add new CSP)
      const existingCSP = responseHeaders['content-security-policy'];
      if (existingCSP) {
        const merged = mergeNonce(existingCSP, nonce);
        if (merged.modified) {
          responseHeaders['content-security-policy'] = merged.csp;
          request.log.debug({ requestId, hostname }, 'CSP nonce merged');
        }
      }

      // Also check report-only CSP
      const existingCSPRO = responseHeaders['content-security-policy-report-only'];
      if (existingCSPRO) {
        const mergedRO = mergeNonce(existingCSPRO, nonce);
        if (mergedRO.modified) {
          responseHeaders['content-security-policy-report-only'] = mergedRO.csp;
        }
      }

      // Merge connect-src for cross-origin config fetch (static loader mode)
      const apiBase = config.bootstrapper?.api_base;
      if (apiBase) {
        const cspToMerge = responseHeaders['content-security-policy'];
        if (cspToMerge) {
          const mergedConnect = mergeConnectSrc(cspToMerge, apiBase);
          if (mergedConnect.modified) {
            responseHeaders['content-security-policy'] = mergedConnect.csp;
            request.log.debug({ requestId, hostname }, 'CSP connect-src merged for API base');
          }
        }
      }
    }

    // 5. Route: HTML or passthrough
    // We use Fastify's native .send(stream) instead of reply.raw.writeHead
    // to prevent Fastify from prematurely closing the connection when the handler returns.
    reply.headers(responseHeaders);
    reply.code(upstream.statusCode);

    if (isHtml) {
      // 5a. Auto-Decompress if the origin ignored our preference and sent compressed HTML
      let htmlStream = applyDecompressor(upstream.body, reply, request, requestId);

      // ── Circuit Breaker Check ─────────────────────────────────
      // If the transform pipeline has been consistently failing for this domain,
      // bypass transforms and serve raw origin content to avoid breaking the page.
      const cbCheck = shouldTransform(hostname);

      // ── Geo-Restriction Bypass Check ─────────────────────────────
      // If the owner configured specific countries to NEVER see the banner (e.g. US traffic),
      // we completely skip the HTML transforms so native performance is zero-delay.
      const cfIpCountry = request.headers['cf-ipcountry'];
      let skipGeoTransforms = false;
      if (cfIpCountry && Array.isArray(config.geo_skipped_countries) && config.geo_skipped_countries.includes(cfIpCountry.toUpperCase())) {
          skipGeoTransforms = true;
          reply.header('x-yc-geo-bypass', 'true');
          try { inc('geo_bypass'); } catch (e) {}
          request.log.info({ requestId, hostname, country: cfIpCountry }, 'Geo-Restriction Bypass triggered — skipping HTML transforms');
      }

      // HTML: pipe through transform pipeline
      // Order: upstream → decompressor → injector (adds YCookies script) → blocker (blocks 3p)
      // The injector runs FIRST so the blocker sees the injected ycookies-manager
      // script and auto-protects it via self-tag detection.
      const transformStart = Date.now();
      const NONCE_TOKEN = '__YCOOKIES_NONCE_TOKEN__';
      let outStream;
      let injector = null;
      const nonceReplacer = createNonceReplaceStream(NONCE_TOKEN, nonce);

      if (!cbCheck.allow || skipGeoTransforms) {
        if (!cbCheck.allow) {
          // Circuit is OPEN — bypass transforms, serve raw origin HTML
          inc('circuit_breaker_bypass');
          request.log.warn({ requestId, hostname, cbState: cbCheck.state }, 'circuit breaker OPEN — bypassing HTML transforms');
        }
        outStream = htmlStream;
      } else {
        if (cbCheck.halfOpen) {
          inc('circuit_breaker_probe');
          request.log.info({ requestId, hostname }, 'circuit breaker HALF_OPEN — probing with transforms');
        }

        const isGpcSet = request.headers['sec-gpc'] === '1' || request.headers['sec-gpc'] === '"1"';
        injector = createHtmlInjector(config, { nonce: NONCE_TOKEN, gpc: isGpcSet });

        // Always pipe through the blocker stream: script/content rules may be empty, but
        // html-blocker.js still applies universal third-party iframe blocking when site_host is set.
        const blockerConfig = { ...config, site_host: hostname, auto_blocking: config.auto_blocking };
        const blocker = createBlockerStream(blockerConfig);
        outStream = htmlStream.pipe(injector).pipe(blocker);
      }

      // If eligible for caching, tap the stream BEFORE nonce-replacement
      if (cacheResult.eligible) {
        const { Transform } = await import('node:stream');
        const chunks = [];
        let accumulatedBytes = 0;
        let cacheAborted = false;
        
        // 2MB ceiling globally (allows robust caching while blocking malicious/un-optimized payloads)
        const MAX_CACHE_BYTES = config.proxy?.max_cache_kb 
          ? config.proxy.max_cache_kb * 1024 
          : 2 * 1024 * 1024; 
          
        // Inject SWR Stale Threshold into Cached Headers
        // Default TTL fresh for 5 minutes, Stale for 24 hours. (So 24h total storage time in Redis).
        const FRESH_TTL = 300; 
        const SWR_WINDOW = 86400; // 24 hours
        cacheHeadersCopy['x-yc-stale-at'] = Date.now() + (FRESH_TTL * 1000);

        const cacheWriter = new Transform({
          transform(chunk, enc, cb) {
            // Unconditionally pass the chunk downstream so user's browser isn't interrupted
            cb(null, chunk);
            
            if (!cacheAborted) {
              accumulatedBytes += chunk.length;
              if (accumulatedBytes > MAX_CACHE_BYTES) {
                cacheAborted = true;
                // Instantly purge buffer references to free V8 GC
                chunks.length = 0; 
                request.log.warn({ requestId, hostname, bytes: accumulatedBytes }, 'Payload limits exceeded, cache write aborted cleanly');
              } else {
                chunks.push(chunk);
              }
            }
          },
          flush(cb) {
            if (!cacheAborted && chunks.length > 0) {
              const bodyBuf = Buffer.concat(chunks);
              // Set Physical TTL to cover the entire SWR Grace Window
              setCache(cacheResult.cacheKey, cacheHeadersCopy, bodyBuf, FRESH_TTL + SWR_WINDOW).catch(err => {
                request.log.error({ err: err.message }, 'Cache write failed');
              });
            }
            cb();
          }
        });
        outStream = outStream.pipe(cacheWriter);
      }

      // Add the nonce replacer at the very end
      outStream = outStream.pipe(nonceReplacer);

      // Catch pipeline errors — prevents silent hangs on corrupted streams
      // Also feeds the circuit breaker so repeated failures trigger bypass.
      outStream.on('error', (err) => {
        cleanupUpstreamGuards();
        request.log.error({ requestId, hostname, err: err.message }, 'HTML stream pipeline error');
        if (cbCheck.allow) {
          recordFailure(hostname);
          inc('circuit_breaker_error');
        }
        // Structured error reporting → GlitchTip/Sentry
        Sentry.withScope((scope) => {
          scope.setTag('domain', hostname);
          scope.setTag('component', 'html_mutation');
          scope.setTag('circuit_state', cbCheck.state);
          scope.setExtra('requestId', requestId);
          scope.setExtra('method', request.method);
          scope.setExtra('path', request.raw.url);
          scope.setExtra('injectionPath', injector?.injectionPath || 'unknown');
          Sentry.captureException(err);
        });
      });

      // Record edge metric for HTML responses
      outStream.on('end', () => {
        cleanupUpstreamGuards();
        // Track injection path for observability
        if (injector) inc(`inject_${injector.injectionPath}`);

        // If the injector caught an internal error (per-chunk fallback),
        // record it as a circuit breaker failure + send to GlitchTip
        if (injector?.injectionError) {
          const injErr = injector.injectionError;
          request.log.warn({ requestId, hostname, err: injErr.message }, 'HTML injector internal error — fell back to pass-through');
          if (cbCheck.allow) {
            recordFailure(hostname);
            inc('circuit_breaker_error');
          }
          Sentry.withScope((scope) => {
            scope.setTag('domain', hostname);
            scope.setTag('component', 'html_injector');
            scope.setTag('fallback', 'error_passthrough');
            scope.setExtra('requestId', requestId);
            scope.setExtra('path', request.raw.url);
            scope.setExtra('injectionPath', injector.injectionPath);
            Sentry.captureException(injErr);
          });
        } else if (cbCheck.allow) {
          // Record circuit breaker success when transforms completed without error
          recordSuccess(hostname);
        }

        const duration = Date.now() - startTime;
        timing.transform_ms = Date.now() - transformStart;
        timing.origin_body_ms = transformStart - originFetchStart - timing.origin_ttfb_ms;
        timing.total_ms = duration;

        if (duration > 5000) {
            request.log.warn({ ...timing, domain: hostname, path: request.raw.url }, 'slow request');
        }

        // Structured timing log — one line per HTML request with full stage breakdown
        request.log.info({
          requestId,
          hostname,
          method: request.method,
          path: request.raw.url,
          status: upstream.statusCode,
          timing,
        }, 'request timing');

        recordMetric({
          domain:           hostname,
          method:           request.method,
          status:           upstream.statusCode,
          duration_ms:      duration,
          origin_ttfb_ms:   timing.origin_ttfb_ms,
          from_cache:       'miss', // Phase 1a dry-run: always went to origin
          response_type:    'html',
          html_injected:    injector ? injector.injected !== false : false,
          blocked_scripts:  injector ? (outStream._blockedScripts || 0) : 0,
          blocked_content:  injector ? (outStream._blockedContent || 0) : 0,
          filtered_cookies: rawSetCookies ? filterCookies(rawSetCookies, config.cookie_policy).blocked.length : 0,
          csp_nonce_added:  nonce !== null && !!responseHeaders['content-security-policy'],
          bytes_in:         parseInt(request.headers['content-length'] || '0', 10),
          bytes_out:        0, // stream — not easily known
          error_code:       null,
          config_version:   config.revision || 0,
        });
      });

      return reply.send(outStream);
    } else {
      // Non-HTML: direct streaming passthrough
      // Attach completion logging before sending
      upstream.body.on('end', () => {
        cleanupUpstreamGuards();
        const duration = Date.now() - startTime;
        timing.total_ms = duration;

        if (duration > 5000) {
            request.log.warn({ ...timing, domain: hostname, path: request.raw.url }, 'slow request');
        }

        request.log.info({
          requestId,
          hostname,
          method: request.method,
          path: request.raw.url,
          status: upstream.statusCode,
          type: 'passthrough',
          timing,
        }, 'request timing');

        // Record edge metric for passthrough responses
        recordMetric({
          domain:           hostname,
          method:           request.method,
          status:           upstream.statusCode,
          duration_ms:      duration,
          origin_ttfb_ms:   timing.origin_ttfb_ms,
          from_cache:       'miss', // Phase 1a dry-run: always went to origin
          response_type:    'passthrough',
          html_injected:    null,
          blocked_scripts:  0,
          blocked_content:  0,
          filtered_cookies: rawSetCookies ? filterCookies(rawSetCookies, config.cookie_policy).blocked.length : 0,
          csp_nonce_added:  false,
          bytes_in:         parseInt(request.headers['content-length'] || '0', 10),
          bytes_out:        parseInt(upstream.headers['content-length'] || '0', 10),
          error_code:       null,
          config_version:   config.revision || 0,
        });
      });
      upstream.body.on('error', (err) => {
        cleanupUpstreamGuards();
        request.log.error({
          requestId,
          hostname,
          err: err.message,
        }, 'upstream body stream error');
      });
      return reply.send(upstream.body);
    }

  } catch (err) {
    cleanupUpstreamGuards();
    const duration = Date.now() - startTime;
    timing.total_ms = duration;
    const clientDisconnected = upstreamAbort.signal.aborted &&
      upstreamAbort.signal.reason instanceof Error &&
      upstreamAbort.signal.reason.message.includes('client disconnected');
    const shouldRecyclePool = !!config?.origin?.ip && (
      upstreamTimedOut ||
      err.code === 'UND_ERR_HEADERS_TIMEOUT' ||
      err.code === 'UND_ERR_BODY_TIMEOUT'
    );

    if (shouldRecyclePool) {
      const recycled = destroyAgent(config.origin.ip, originHost);
      if (recycled) {
        request.log.warn({
          requestId,
          hostname,
          pool: `${config.origin.ip}:${originHost}`,
          reason: upstreamTimedOut ? 'overall_timeout' : err.code,
        }, 'origin agent pool recycled');
      }
    }

    if (clientDisconnected) {
      request.log.info({
        requestId,
        hostname,
        method: request.method,
        path: request.raw.url,
        timing,
      }, 'client disconnected before upstream completed');
      return;
    }

    request.log.error({
      requestId,
      hostname,
      method: request.method,
      path: request.raw.url,
      err: err.message,
      timing,
    }, 'upstream fetch failed');

    // Record edge metric for failed upstream fetch
    const errorCode = err.code === 'UND_ERR_HEADERS_TIMEOUT' ? 'TIMEOUT'
      : err.code === 'UND_ERR_BODY_TIMEOUT' ? 'BODY_TIMEOUT'
      : 'UPSTREAM_ERROR';
    recordMetric({
      domain:           hostname,
      method:           request.method,
      status:           502,
      duration_ms:      duration,
      origin_ttfb_ms:   null,
      from_cache:       'miss',
      response_type:    'error',
      html_injected:    null,
      blocked_scripts:  0,
      blocked_content:  0,
      filtered_cookies: 0,
      csp_nonce_added:  false,
      bytes_in:         parseInt(request.headers['content-length'] || '0', 10),
      bytes_out:        0,
      error_code:       errorCode,
      config_version:   config?.revision || 0,
    });

    if (reply.raw.destroyed || reply.sent) {
      return;
    }

    return sendProxyError(reply, request, PROXY_ERRORS.UPSTREAM_TIMEOUT, 'Origin Server Unreachable');
  }
};


}