<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outbound_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // click|open|reply|signup|payment|custom
            $table->float('value');
            $table->string('source'); // stripe|tracking_pixel|webhook|manual
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['experiment_id', 'type']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
