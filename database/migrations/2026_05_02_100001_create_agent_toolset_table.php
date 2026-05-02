<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_toolset', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('toolset_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('auto_select')->default(false);
            $table->timestamps();
            $table->primary(['agent_id', 'toolset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_toolset');
    }
};
