import {
    readFileSync,
    writeFileSync,
    unlinkSync,
    mkdirSync,
    readdirSync,
    renameSync,
} from "node:fs";
import { join } from "node:path";

const SNAPSHOT_DIR = process.env.CONFIG_SNAPSHOT_DIR || "/data/config-cache";

/**
 * Save config snapshot to disk for disaster recovery.
 * Uses atomic writes (temp-file + rename).
 */
export function saveSnapshot(hostname, configStr) {
    try {
        mkdirSync(SNAPSHOT_DIR, { recursive: true });
        const safeName = hostname.replace(/[^a-zA-Z0-9.-]/g, "_");
        const finalPath = join(SNAPSHOT_DIR, `${safeName}.json`);
        const tmpPath = finalPath + '.tmp';
        writeFileSync(tmpPath, configStr, "utf8");
        renameSync(tmpPath, finalPath);
    } catch (err) {
        console.error(
            `[Disk Snapshot] Failed to save ${hostname}: ${err.message}`,
        );
    }
}

/**
 * Delete config snapshot from disk.
 * Called on domain delete/disable to prevent stale resurrection.
 */
export function deleteSnapshot(hostname) {
    try {
        const safeName = hostname.replace(/[^a-zA-Z0-9.-]/g, "_");
        unlinkSync(join(SNAPSHOT_DIR, `${safeName}.json`));
    } catch {
        // File may not exist — that's fine
    }
}

/**
 * Load config snapshot from disk.
 * Returns parsed config or null if not found/corrupt.
 */
export function loadSnapshot(hostname) {
    try {
        const safeName = hostname.replace(/[^a-zA-Z0-9.-]/g, "_");
        const raw = readFileSync(
            join(SNAPSHOT_DIR, `${safeName}.json`),
            "utf8",
        );
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

/**
 * Pre-warm in-memory cache from disk snapshots on boot.
 * Called once at startup so the proxy can serve immediately.
 */
export function preWarmFromDisk(cacheSet, jitteredTTL) {
    try {
        mkdirSync(SNAPSHOT_DIR, { recursive: true });
        const files = readdirSync(SNAPSHOT_DIR).filter((f) =>
            f.endsWith(".json"),
        );
        let loaded = 0;
        for (const file of files) {
            try {
                const raw = readFileSync(join(SNAPSHOT_DIR, file), "utf8");
                const config = JSON.parse(raw);
                if (config && config.domain) {
                    cacheSet(config.domain, {
                        config,
                        expiresAt: Date.now() + jitteredTTL(300000), // 5 min TTL
                        revision: config.revision || 0,
                        fetchedAt: Date.now(),
                        source: "disk",
                    });
                    loaded++;
                }
            } catch (parseErr) {
                console.error(`[Disk Snapshot] Failed to parse ${file}`);
            }
        }
        console.log(`[Disk Snapshot] Pre-warmed ${loaded} domains from disk`);
    } catch (dirErr) {
        console.log(`[Disk Snapshot] No existing snapshots found in ${SNAPSHOT_DIR}`);
    }
}
