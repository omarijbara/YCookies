/**
 * SSRF Protection — Private IP validation for upstream origins.
 *
 * Mirrors Laravel's UrlValidator logic. Validates that origin IPs
 * are not in private/reserved ranges before fetching.
 *
 * Defense-in-depth: also resolves hostnames via DNS and validates
 * the resolved IP is not private (prevents DNS rebinding attacks).
 */

import { isIPv4, isIPv6 } from 'node:net';
import dns from 'node:dns';

/**
 * RFC 1918 + RFC 6890 private/reserved IPv4 ranges.
 */
const PRIVATE_V4_RANGES = [
  { prefix: '10.', mask: null },              // 10.0.0.0/8
  { prefix: '172.', min: 16, max: 31 },       // 172.16.0.0/12
  { prefix: '192.168.', mask: null },          // 192.168.0.0/16
  { prefix: '127.', mask: null },              // 127.0.0.0/8 (loopback)
  { prefix: '169.254.', mask: null },          // 169.254.0.0/16 (link-local)
  { prefix: '0.', mask: null },               // 0.0.0.0/8
];

/**
 * Check if an IPv4 address is in a private/reserved range.
 *
 * @param {string} ip
 * @returns {boolean}
 */
function isPrivateIPv4(ip) {
  if (ip === '0.0.0.0' || ip === 'localhost') return true;

  for (const range of PRIVATE_V4_RANGES) {
    if (ip.startsWith(range.prefix)) {
      if (!range.min) return true; // Simple prefix match

      // Check 172.16-31.x.x range
      const secondOctet = parseInt(ip.split('.')[1], 10);
      if (secondOctet >= range.min && secondOctet <= range.max) return true;
    }
  }

  return false;
}

/**
 * Check if an IPv6 address is private/reserved.
 *
 * @param {string} ip
 * @returns {boolean}
 */
function isPrivateIPv6(ip) {
  const lower = ip.toLowerCase();

  if (lower === '::1') return true;                    // Loopback
  if (lower.startsWith('fe80:')) return true;           // Link-local
  if (lower.startsWith('fc') || lower.startsWith('fd')) return true; // Unique local
  if (lower.startsWith('::ffff:')) {                    // IPv4-mapped
    const v4 = lower.replace('::ffff:', '');
    return isPrivateIPv4(v4);
  }

  return false;
}

/**
 * Check if an IP address (v4 or v6) is private/reserved.
 *
 * @param {string} ip
 * @returns {boolean}
 */
export function isPrivateIP(ip) {
  if (isIPv4(ip)) return isPrivateIPv4(ip);
  if (isIPv6(ip)) return isPrivateIPv6(ip);
  return false;
}

/**
 * Validate that an IP address is not private/reserved.
 * Throws an Error if the IP is private.
 *
 * @param {string} ip
 * @throws {Error}
 */
export function assertPublicIP(ip) {
  if (!ip) return; // No IP to validate (URL-based origin)

  if (isIPv4(ip)) {
    if (isPrivateIPv4(ip)) {
      throw new Error(`SSRF blocked: ${ip} is a private IPv4 address`);
    }
    return;
  }

  if (isIPv6(ip)) {
    if (isPrivateIPv6(ip)) {
      throw new Error(`SSRF blocked: ${ip} is a private IPv6 address`);
    }
    return;
  }

  // Not a valid IP format — could be a hostname, which is fine
  // (DNS resolution happens at the HTTP client level)
}

/**
 * Validate a URL hostname is not private.
 *
 * @param {string} url
 * @throws {Error}
 */
export function assertPublicUrl(url) {
  const parsed = new URL(url);

  // Only allow http and https
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error(`SSRF blocked: invalid protocol ${parsed.protocol}`);
  }

  const hostname = parsed.hostname.replace(/^\[|\]$/g, ''); // Strip IPv6 brackets

  // Check common dangerous hostnames
  if (hostname === 'localhost' || hostname === '0.0.0.0') {
    throw new Error(`SSRF blocked: ${hostname} is not allowed`);
  }

  // Check mDNS suffixes
  if (/\.(local|lan|home|internal|home\.arpa)$/i.test(hostname)) {
    throw new Error(`SSRF blocked: ${hostname} is a local network name`);
  }

  // If hostname looks like an IP, validate it
  if (isIPv4(hostname) || isIPv6(hostname)) {
    assertPublicIP(hostname);
  }
}

/**
 * Resolve a hostname via DNS and validate the resolved IP is not private.
 * Defense-in-depth against DNS rebinding where a hostname resolves to 127.0.0.1.
 *
 * @param {string} hostname - Hostname to resolve (not an IP literal)
 * @returns {Promise<void>}
 * @throws {Error} If resolved IP is private/reserved
 */
export async function assertPublicHostname(hostname) {
  // Skip if hostname is already an IP literal (handled by assertPublicIP)
  if (isIPv4(hostname) || isIPv6(hostname)) {
    assertPublicIP(hostname);
    return;
  }

  // Skip localhost-like names (already caught by assertPublicUrl)
  if (hostname === 'localhost' || hostname === '0.0.0.0') {
    throw new Error(`SSRF blocked: ${hostname} is not allowed`);
  }

  try {
    const { address } = await dns.promises.lookup(hostname);
    if (isPrivateIP(address)) {
      throw new Error(`SSRF blocked: ${hostname} resolves to private IP ${address}`);
    }
  } catch (err) {
    if (err.message.startsWith('SSRF blocked:')) throw err;
    // Fail-closed on DNS resolution errors. 
    // This removes the window where the hostname could resolve to a private IP via a secondary resolver.
    throw new Error(`DNS resolution failed for ${hostname}: ${err.message}`);
  }
}

