<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // email_template|landing_page|pdf|prompt_snapshot|analysis
            $table->string('name');
            $table->integer('current_version')->default(1);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
