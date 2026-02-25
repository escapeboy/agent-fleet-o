<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_node_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->uuid('playbook_step_id')->nullable()->index();
            $table->uuid('workflow_node_id')->index();
            $table->string('node_type', 50);
            $table->string('node_label')->nullable();
            // event_type: started | completed | failed | skipped | waiting_time | waiting_human
            $table->string('event_type', 30);
            // root_event_id: the first event in this execution chain (self-ref)
            $table->uuid('root_event_id')->nullable()->index();
            // parent_event_id: the directly preceding event (self-ref, for chain)
            $table->uuid('parent_event_id')->nullable();
            $table->text('input_summary')->nullable();
            $table->text('output_summary')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'event_type']);
            $table->index(['experiment_id', 'created_at']);
        });

        // Add root_event_id to playbook_steps for fast chain lookup
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->uuid('root_event_id')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropColumn('root_event_id');
        });

        Schema::dropIfExists('workflow_node_events');
    }
};
