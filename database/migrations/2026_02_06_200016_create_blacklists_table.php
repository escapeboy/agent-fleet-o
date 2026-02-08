<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // email|domain|company|keyword
            $table->string('value');
            $table->string('reason')->nullable();
            $table->foreignUuid('added_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};
