<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_knowledge_base', function (Blueprint $table) {
            $table->uuid('agent_id');
            $table->uuid('knowledge_base_id');
            $table->timestamps();

            $table->primary(['agent_id', 'knowledge_base_id']);
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases')->cascadeOnDelete();
        });

        // Migrate existing knowledge_base_id FK data into pivot
        $now = now()->toDateTimeString();
        DB::table('agents')
            ->whereNotNull('knowledge_base_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function ($agent) use ($now) {
                DB::table('agent_knowledge_base')->insert([
                    'agent_id' => $agent->id,
                    'knowledge_base_id' => $agent->knowledge_base_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_knowledge_base');
    }
};
