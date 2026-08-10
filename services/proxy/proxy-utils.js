/**
 * Converts a partial or non-existent auto_blocking config into a strict boolean map.
 * Defaults to true for all properties if undefined.
 * 
 * @param {object|undefined} autoBlocking 
 * @returns {{ content: boolean, script: boolean, style: boolean, service: boolean }}
 */
export function normalizeAutoBlockingConfig(autoBlocking) {
  const defaults = {
    content: true,
    script: true,
    style: true,
    service: true,
  };

  const candidate = autoBlocking && typeof autoBlocking === 'object'
    ? autoBlocking
    : {};

  return {
    content: candidate.content !== undefined ? !!candidate.content : defaults.content,
    script: candidate.script !== undefined ? !!candidate.script : defaults.script,
    style: candidate.style !== undefined ? !!candidate.style : defaults.style,
    service: candidate.service !== undefined ? !!candidate.service : defaults.service,
  };
}

/**
 * Select the correct origin auth token during grace-period rotation.
 * During the 24h grace window after rotation, sends the LEGACY token
 * so the origin server can still verify requests while the customer
 * updates their config. After expiry, switches to the new primary token.
 *
 * @param {object} origin - config.origin from Laravel API
 * @returns {string|null}
 */
export function selectOriginAuthToken(origin) {
  if (
    origin.auth_token_legacy &&
    origin.auth_legacy_expires_at &&
    new Date() < new Date(origin.auth_legacy_expires_at)
  ) {
    return origin.auth_token_legacy;
  }
  return origin.auth_token;
}
