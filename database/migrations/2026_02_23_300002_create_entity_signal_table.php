<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_signal', function (Blueprint $table) {
            $table->foreignUuid('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('signal_id')->constrained()->cascadeOnDelete();
            $table->string('context')->nullable();
            $table->float('confidence')->default(1.0);
            $table->timestamps();

            $table->primary(['entity_id', 'signal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_signal');
    }
};
