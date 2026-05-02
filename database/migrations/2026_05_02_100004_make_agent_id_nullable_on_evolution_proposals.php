<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable(false)->change();
        });
    }
};
