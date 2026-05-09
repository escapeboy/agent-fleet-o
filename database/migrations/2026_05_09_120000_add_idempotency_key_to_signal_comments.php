<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_comments', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('body');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS signal_comments_signal_idem_unique '
                .'ON signal_comments (signal_id, idempotency_key) '
                .'WHERE idempotency_key IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS signal_comments_signal_idem_unique');
        }

        Schema::table('signal_comments', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
