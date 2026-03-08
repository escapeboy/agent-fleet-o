<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Upgrade WebhookEndpoint.secret from APP_KEY `encrypted` cast to per-team
 * TeamEncryptedString (XSalsa20-Poly1305 under the team's DEK).
 *
 * No column type change needed — the column is already TEXT.
 * Actual data migration is handled by `php artisan credentials:re-encrypt`
 * after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column stays TEXT — no DDL change required.
        // Data migration: run `php artisan credentials:re-encrypt` after deploying.
    }

    public function down(): void
    {
        // Reversing data migration would require re-encrypt in the opposite direction.
        // If needed, run credentials:re-encrypt with the old cast reverted.
    }
};
