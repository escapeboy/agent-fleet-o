<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The marketplace_listings table has a strict team_isolation RLS policy that
     * only allows rows where team_id = current_team_id(). This blocks platform
     * (is_official) listings which belong to the platform team, not the user's team.
     *
     * Fix: replace the single FOR ALL policy with two policies:
     *   - SELECT: allow own team + any public listing
     *   - Write (INSERT, UPDATE, DELETE): own team only
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='marketplace_listings'",
        );

        if (! $exists) {
            return;
        }

        // Drop existing policies (idempotent)
        DB::statement('DROP POLICY IF EXISTS team_isolation ON marketplace_listings');
        DB::statement('DROP POLICY IF EXISTS marketplace_listings_read ON marketplace_listings');
        DB::statement('DROP POLICY IF EXISTS marketplace_listings_write ON marketplace_listings');

        // Read policy: own listings + any publicly visible listing
        DB::statement("
            CREATE POLICY marketplace_listings_read ON marketplace_listings
            AS PERMISSIVE
            FOR SELECT
            USING (team_id = current_team_id() OR visibility = 'public')
        ");

        // Write policy: own team only (prevents writing to other teams' listings)
        DB::statement('
            CREATE POLICY marketplace_listings_write ON marketplace_listings
            AS PERMISSIVE
            FOR ALL
            USING (team_id = current_team_id())
            WITH CHECK (team_id = current_team_id())
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='marketplace_listings'",
        );

        if (! $exists) {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS marketplace_listings_read ON marketplace_listings');
        DB::statement('DROP POLICY IF EXISTS marketplace_listings_write ON marketplace_listings');

        // Restore original strict policy
        DB::statement('
            CREATE POLICY team_isolation ON marketplace_listings
            AS PERMISSIVE
            FOR ALL
            USING (team_id = current_team_id())
            WITH CHECK (team_id = current_team_id())
        ');
    }
};
