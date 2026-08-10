import { createHmac, timingSafeEqual } from "node:crypto";

const SHARED_SECRET = process.env.PROXY_SHARED_SECRET || "";
const SHARED_SECRET_PREV = process.env.PROXY_SHARED_SECRET_PREV || "";

/**
 * Signs an outgoing config fetch request to Laravel.
 * @param {string} hostname
 * @returns {string|null} Hex signature
 */
export function signRequest(hostname) {
    if (!SHARED_SECRET) return null;
    return createHmac("sha256", SHARED_SECRET)
        .update(String(hostname).trim().toLowerCase())
        .digest("hex");
}

/**
 * Verifies the HMAC-SHA256 signature returned by Laravel.
 * Security policy: signature failures are FAIL-CLOSED.
 * @param {string} hostname
 * @param {string} signature Header value
 * @param {string} bodyText Raw response body
 * @throws {Error} if verification fails
 */
export function verifySignature(hostname, signature, bodyText) {
    if (!SHARED_SECRET) return;

    if (!signature) {
        throw new Error(
            `[HMAC] Missing X-Signature header for ${hostname}`,
        );
    }

    if (!/^[0-9a-fA-F]{64}$/.test(signature)) {
        throw new Error(
            `[HMAC] Malformed signature header for ${hostname}`,
        );
    }

    const received = Buffer.from(signature, "hex");
    const expected = createHmac("sha256", SHARED_SECRET)
        .update(bodyText)
        .digest();
        
    let verified =
        received.length === expected.length &&
        timingSafeEqual(received, expected);

    if (!verified && SHARED_SECRET_PREV) {
        const expectedPrev = createHmac("sha256", SHARED_SECRET_PREV)
            .update(bodyText)
            .digest();
        verified =
            received.length === expectedPrev.length &&
            timingSafeEqual(received, expectedPrev);
    }

    if (!verified) {
        throw new Error(
            `[HMAC] Signature verification failed for ${hostname}`,
        );
    }
}
