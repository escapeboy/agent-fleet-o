<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_watchdog_runs', function (Blueprint $table) {
            $table->timestamp('digest_sent_at')->nullable();
        });

        // Earlier runs already sent their own message: the first digest must not repeat them all
        DB::table('sentry_watchdog_runs')->whereNotNull('finished_at')->update(['digest_sent_at' => DB::raw('finished_at')]);
    }

    public function down(): void
    {
        Schema::table('sentry_watchdog_runs', function (Blueprint $table) {
            $table->dropColumn('digest_sent_at');
        });
    }
};
