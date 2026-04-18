<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('widget_public_key', 64)->unique()->nullable()->after('credential_key');
        });

        // Backfill existing teams
        DB::table('teams')->whereNull('widget_public_key')->lazyById()->each(function ($team) {
            DB::table('teams')
                ->where('id', $team->id)
                ->update(['widget_public_key' => 'wk_'.Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('widget_public_key');
        });
    }
};
