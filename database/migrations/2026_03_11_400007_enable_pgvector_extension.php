<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $available = DB::scalar("SELECT COUNT(*) FROM pg_available_extensions WHERE name = 'vector'") > 0;

        if ($available) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    public function down(): void
    {
        // Leave extension in place — other features (SemanticCache) may depend on it
    }
};
