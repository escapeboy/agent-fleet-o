<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_edges', function (Blueprint $table) {
            // source_channel: which output port on the source node this edge connects from
            // (e.g. "on_success", "on_error", "on_timeout"). Null = default/unconditional.
            $table->string('source_channel', 100)->nullable()->after('sort_order');

            // target_channel: which input slot on the target node this edge connects to.
            // Used for named input binding on multi-input nodes.
            $table->string('target_channel', 100)->nullable()->after('source_channel');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_edges', function (Blueprint $table) {
            $table->dropColumn(['source_channel', 'target_channel']);
        });
    }
};
