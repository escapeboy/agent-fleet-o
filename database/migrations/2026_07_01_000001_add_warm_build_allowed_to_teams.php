<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Per-team allow for the hostile-isolation warm-build path. Default
            // OFF: only explicitly-trusted teams get it, and only while the
            // global EXPERIMENTS_WARM_BUILD master switch is also on.
            $table->boolean('warm_build_allowed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('warm_build_allowed');
        });
    }
};
