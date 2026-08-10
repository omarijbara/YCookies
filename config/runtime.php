<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Runtime Manifest Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the compiled runtime manifest architecture.
    |
    */

    // Ed25519 signing private key. In production, set via Coolify env var.
    // If empty, an ephemeral keypair is generated (safe for dev/test only).
    'signing_private_key' => env('RUNTIME_SIGNING_KEY', ''),

    // Debounce window for compile triggers (seconds).
    // When multiple models change rapidly, only one compile job
    // fires per domain within this window.
    'compile_debounce_seconds' => env('RUNTIME_COMPILE_DEBOUNCE', 5),

    // Maximum number of revisions to retain per domain.
    // Older revisions are pruned on publish (keeps rollback targets).
    'max_revisions_per_domain' => env('RUNTIME_MAX_REVISIONS', 20),

    // Queue name for compile jobs.
    'compile_queue' => env('RUNTIME_COMPILE_QUEUE', 'default'),

    // Shadow diff mode. Set to 'shadow' to enable differential validation
    // between manifest-projected and legacy controller outputs.
    // WARNING: doubles DB cost per request — use only during canary validation.
    'diff_mode' => env('MANIFEST_DIFF_MODE', null),

    // Verify Ed25519 signatures on manifest read (at cache-miss time).
    // Set to false ONLY in emergency to bypass verification.
    'verify_on_read' => env('MANIFEST_VERIFY_ON_READ', true),
];
