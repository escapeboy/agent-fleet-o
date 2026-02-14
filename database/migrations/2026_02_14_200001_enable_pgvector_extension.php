<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Only attempt to enable pgvector if the extension files are installed on the system
        $available = DB::scalar("SELECT COUNT(*) FROM pg_available_extensions WHERE name = 'vector'") > 0;

        if ($available) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS vector');
        }
    }
};
