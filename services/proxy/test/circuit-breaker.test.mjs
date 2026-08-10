import { describe, it, expect, beforeEach } from 'vitest';

// We need to re-import fresh module state for each test
let shouldTransform, recordSuccess, recordFailure, getCircuitStats, cleanupStaleCircuits;

beforeEach(async () => {
  // Dynamic import with cache busting to get fresh module state
  const mod = await import('../circuit-breaker.js?t=' + Date.now() + Math.random());
  shouldTransform = mod.shouldTransform;
  recordSuccess = mod.recordSuccess;
  recordFailure = mod.recordFailure;
  getCircuitStats = mod.getCircuitStats;
  cleanupStaleCircuits = mod.cleanupStaleCircuits;
});

describe('Circuit Breaker', () => {
  it('starts in CLOSED state — transforms allowed', () => {
    const result = shouldTransform('example.com');
    expect(result.allow).toBe(true);
    expect(result.state).toBe('closed');
  });

  it('stays CLOSED after fewer failures than threshold', () => {
    for (let i = 0; i < 4; i++) {
      recordFailure('example.com', { threshold: 5 });
    }
    const result = shouldTransform('example.com');
    expect(result.allow).toBe(true);
    expect(result.state).toBe('closed');
  });

  it('opens after threshold failures', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('example.com', { threshold: 5 });
    }
    const result = shouldTransform('example.com');
    expect(result.allow).toBe(false);
    expect(result.state).toBe('open');
  });

  it('transitions to HALF_OPEN after cooldown', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('example.com', { threshold: 5 });
    }
    // Simulate cooldown elapsed by using a very short cooldown
    const result = shouldTransform('example.com', { cooldownMs: 0 });
    expect(result.allow).toBe(true);
    expect(result.halfOpen).toBe(true);
    expect(result.state).toBe('half_open');
  });

  it('closes circuit after successful half-open probe', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('example.com', { threshold: 5 });
    }
    // Transition to half-open
    shouldTransform('example.com', { cooldownMs: 0 });
    // Record success
    recordSuccess('example.com');
    const result = shouldTransform('example.com');
    expect(result.allow).toBe(true);
    expect(result.state).toBe('closed');
  });

  it('reopens circuit after failed half-open probe', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('example.com', { threshold: 5 });
    }
    // Transition to half-open
    shouldTransform('example.com', { cooldownMs: 0 });
    // Record failure during half-open
    recordFailure('example.com');
    const result = shouldTransform('example.com');
    expect(result.allow).toBe(false);
    expect(result.state).toBe('open');
  });

  it('isolates circuits per domain', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('bad.com', { threshold: 5 });
    }
    expect(shouldTransform('bad.com').allow).toBe(false);
    expect(shouldTransform('good.com').allow).toBe(true);
  });

  it('reports stats for open circuits only', () => {
    for (let i = 0; i < 5; i++) {
      recordFailure('bad.com', { threshold: 5 });
    }
    shouldTransform('good.com'); // creates closed circuit
    const stats = getCircuitStats();
    expect(stats.totalTracked).toBe(2);
    expect(stats.open).toBe(1);
    expect(stats.domains['bad.com']).toBeDefined();
    expect(stats.domains['bad.com'].state).toBe('open');
    expect(stats.domains['good.com']).toBeUndefined(); // closed circuits excluded
  });
});
