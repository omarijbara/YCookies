import { stopAndDrain } from './metrics.js';
import { stopErrorFlush } from './error-reporter.js';

/**
 * Registers graceful shutdown handlers for SIGTERM and SIGINT.
 * Provides a 10s maximum timeout for in-flight requests to complete.
 * 
 * @param {import('fastify').FastifyInstance} fastify
 */
export function registerGracefulShutdown(fastify) {
  const shutdown = async (signal) => {
    fastify.log.info({ signal }, 'Shutting down proxy');

    // Hard exit fallback after 10s if graceful drain gets stuck
    setTimeout(() => {
      fastify.log.error('Graceful shutdown timed out, forcing exit');
      process.exit(1);
    }, 10_000).unref();

    try {
      // 1. Stop accepting new connections and wait for active requests
      // Wrap with an 8s timeout so it doesn't block the final metrics flush if it hangs
      await Promise.race([
          fastify.close(),
          new Promise(resolve => setTimeout(resolve, 8000))
      ]);

      // 2. Flush remaining metrics and errors
      await stopAndDrain();
      stopErrorFlush();
    } catch (err) {
      fastify.log.error({ err: err.message }, 'Error during shutdown');
    }

    process.exit(0);
  };

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}
