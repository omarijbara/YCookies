/**
 * Circuit Breaker — Per-Domain HTML Mutation Pipeline Protection
 *
 * If the injector/blocker consistently fails for a specific domain
 * (e.g. corrupt HTML, encoding issues), the circuit "opens" and we
 * bypass transformation for that domain, serving raw origin content.
 *
 * States:
 *   CLOSED   — normal operation, transforms applied
 *   OPEN     — too many failures, transforms bypassed
 *   HALF_OPEN — testing one request with transforms to see if issue resolved
 *
 * Configuration:
 *   - threshold: errors before opening (default 5)
 *   - windowMs: sliding window for counting errors (default 60s)
 *   - cooldownMs: how long the circuit stays open before half-open (default 30s)
 */

const STATES = {
  CLOSED: 'closed',
  OPEN: 'open',
  HALF_OPEN: 'half_open',
};

let log = console;
export function setCircuitBreakerLogger(logger) {
  log = logger;
}

/** @type {Map<string, { state: string, errors: number[], openedAt: number }>} */
const circuits = new Map();

const DEFAULT_THRESHOLD = 5;
const DEFAULT_WINDOW_MS = 60_000;     // 60s sliding window
const DEFAULT_COOLDOWN_MS = 30_000;   // 30s cooldown before half-open

/**
 * Get or create a circuit for a domain.
 * @param {string} domain
 * @returns {{ state: string, errors: number[], openedAt: number }}
 */
function getCircuit(domain) {
  if (!circuits.has(domain)) {
    circuits.set(domain, {
      state: STATES.CLOSED,
      errors: [],
      openedAt: 0,
    });
  }
  return circuits.get(domain);
}

/**
 * Prune old errors outside the sliding window.
 * @param {number[]} errors
 * @param {number} windowMs
 */
function pruneErrors(errors, windowMs) {
  const cutoff = Date.now() - windowMs;
  while (errors.length > 0 && errors[0] < cutoff) {
    errors.shift();
  }
}

/**
 * Check if the circuit allows HTML transformation for this domain.
 *
 * Returns:
 *   - { allow: true }  → proceed with transforms
 *   - { allow: false }  → bypass transforms (serve raw origin)
 *   - { allow: true, halfOpen: true } → testing: apply transforms, watch for error
 *
 * @param {string} domain
 * @param {{ threshold?: number, windowMs?: number, cooldownMs?: number }} [opts]
 * @returns {{ allow: boolean, halfOpen?: boolean, state: string }}
 */
export function shouldTransform(domain, opts = {}) {
  const threshold = opts.threshold ?? DEFAULT_THRESHOLD;
  const windowMs = opts.windowMs ?? DEFAULT_WINDOW_MS;
  const cooldownMs = opts.cooldownMs ?? DEFAULT_COOLDOWN_MS;

  const circuit = getCircuit(domain);

  if (circuit.state === STATES.CLOSED) {
    return { allow: true, state: STATES.CLOSED };
  }

  if (circuit.state === STATES.OPEN) {
    // Check if cooldown has elapsed → transition to half-open
    if (Date.now() - circuit.openedAt >= cooldownMs) {
      log.warn({ domain, from: STATES.OPEN, to: STATES.HALF_OPEN, errorCount: circuit.errors.length }, 'circuit breaker state change');
      circuit.state = STATES.HALF_OPEN;
      return { allow: true, halfOpen: true, state: STATES.HALF_OPEN };
    }
    // Still in cooldown — bypass transforms
    return { allow: false, state: STATES.OPEN };
  }

  // HALF_OPEN — only allow one probe request, block others
  return { allow: false, state: STATES.HALF_OPEN };
}

/**
 * Record a successful transform for a domain (resets circuit if half-open).
 * @param {string} domain
 */
export function recordSuccess(domain) {
  const circuit = getCircuit(domain);

  if (circuit.state === STATES.HALF_OPEN) {
    // Half-open probe succeeded — close the circuit
    log.warn({ domain, from: STATES.HALF_OPEN, to: STATES.CLOSED, errorCount: circuit.errors.length }, 'circuit breaker state change');
    circuit.state = STATES.CLOSED;
    circuit.errors = [];
    circuit.openedAt = 0;
  }
  // CLOSED state: nothing to do (errors auto-expire via sliding window)
}

/**
 * Record a transform failure for a domain.
 * If threshold exceeded within window, opens the circuit.
 *
 * @param {string} domain
 * @param {{ threshold?: number, windowMs?: number }} [opts]
 */
export function recordFailure(domain, opts = {}) {
  const threshold = opts.threshold ?? DEFAULT_THRESHOLD;
  const windowMs = opts.windowMs ?? DEFAULT_WINDOW_MS;

  const circuit = getCircuit(domain);

  if (circuit.state === STATES.HALF_OPEN) {
    // Half-open probe failed — reopen the circuit
    log.warn({ domain, from: STATES.HALF_OPEN, to: STATES.OPEN, errorCount: circuit.errors.length }, 'circuit breaker state change');
    circuit.state = STATES.OPEN;
    circuit.openedAt = Date.now();
    return;
  }

  // CLOSED: accumulate errors
  circuit.errors.push(Date.now());
  pruneErrors(circuit.errors, windowMs);

  if (circuit.errors.length >= threshold) {
    log.warn({ domain, from: STATES.CLOSED, to: STATES.OPEN, errorCount: circuit.errors.length }, 'circuit breaker state change');
    circuit.state = STATES.OPEN;
    circuit.openedAt = Date.now();
    circuit.errors = [];
  }
}

/**
 * Get circuit breaker stats for all domains (for /statsz).
 * @returns {object}
 */
export function getCircuitStats() {
  const stats = {};
  for (const [domain, circuit] of circuits) {
    if (circuit.state !== STATES.CLOSED) {
      stats[domain] = {
        state: circuit.state,
        recentErrors: circuit.errors.length,
        openedAt: circuit.openedAt ? new Date(circuit.openedAt).toISOString() : null,
      };
    }
  }
  return {
    totalTracked: circuits.size,
    open: Object.keys(stats).length,
    domains: stats,
  };
}

/**
 * Periodic cleanup — remove circuits for domains that haven't had errors
 * in a while to prevent unbounded Map growth.
 */
export function cleanupStaleCircuits() {
  const cutoff = Date.now() - 300_000; // 5 minutes
  for (const [domain, circuit] of circuits) {
    if (circuit.state === STATES.CLOSED && circuit.errors.length === 0) {
      circuits.delete(domain);
    } else if (circuit.state === STATES.OPEN && circuit.openedAt < cutoff) {
      // Been open for > 5 minutes without any half-open probe — clean up
      circuits.delete(domain);
    }
  }
}

// Cleanup every 5 minutes
setInterval(cleanupStaleCircuits, 300_000).unref();
