<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tamper-Evident Audit Hash Chain
    |--------------------------------------------------------------------------
    |
    | When enabled, the audit:chain command (scheduled every 5 minutes) links
    | audit_entries into per-team SHA-256 hash chains so that any later
    | mutation or deletion of a chained row is detectable by
    | audit:verify-chain. Rows are chained asynchronously ("notary" model)
    | to keep the audit write path lock-free under queue concurrency.
    |
    | settle_seconds: rows younger than this are skipped so in-flight
    | transactions can land before their UUIDv7 id range is sealed.
    |
    */

    'hash_chain' => [
        'enabled' => (bool) env('AUDIT_HASH_CHAIN_ENABLED', false),
        'settle_seconds' => (int) env('AUDIT_HASH_CHAIN_SETTLE_SECONDS', 120),
        'batch_size' => (int) env('AUDIT_HASH_CHAIN_BATCH_SIZE', 1000),
    ],

];
