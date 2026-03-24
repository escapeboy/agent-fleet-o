<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable()->after('user_id')->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Domain\Agent\Models\Agent::class);
            $table->dropColumn('agent_id');
        });
    }
};
