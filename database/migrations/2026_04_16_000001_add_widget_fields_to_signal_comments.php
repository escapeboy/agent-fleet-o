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
            $table->boolean('widget_visible')->default(false)->after('body');
        });

        // Backfill: agent notes shown to reporter, human notes internal only.
        DB::table('signal_comments')
            ->where('author_type', 'agent')
            ->update(['widget_visible' => true]);

        // Partial index: widget reads filter on signal_id + widget_visible=true.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS signal_comments_widget_visible_idx '
                .'ON signal_comments (signal_id) WHERE widget_visible = true',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS signal_comments_widget_visible_idx');
        }

        Schema::table('signal_comments', function (Blueprint $table) {
            $table->dropColumn('widget_visible');
        });
    }
};
