/**
 * Triggers a Stale-While-Revalidate (SWR) background request using Fastify's
 * native injection engine. This completely bypasses the OS networking stack
 * (no loopback TCP connections) and executes the proxy request synchronously
 * against the router.
 * 
 * @param {import('fastify').FastifyInstance} fastify 
 * @param {import('fastify').FastifyRequest} request 
 * @param {string} secret 
 */
export function triggerSwrRevalidation(fastify, request, secret) {
    const revalidateHeaders = {
        ...request.headers,
        'x-yc-revalidate-secret': secret,
        'host': request.hostname // Ensure proxy routes strictly to the exact tenant via loopback
    };
    
    // Non-blocking programmatic route injection (Direct SWR)
    fastify.inject({
        method: 'GET',
        url: request.raw.url,
        headers: revalidateHeaders
    }).catch(err => {
        request.log.error({ err: err.message, hostname: request.hostname }, 'SWR direct inject failed');
    });
}
