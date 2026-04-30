<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boruna_bundle_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('auditable_decision_id');
            $table->string('status')->default('unverified');
            $table->timestamp('checked_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('auditable_decision_id');
            $table->foreign('auditable_decision_id')
                ->references('id')
                ->on('boruna_auditable_decisions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boruna_bundle_verifications');
    }
};
