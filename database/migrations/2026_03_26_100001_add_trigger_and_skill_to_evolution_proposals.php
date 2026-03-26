<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->string('trigger')->default('manual')->after('status');
            $table->foreignUuid('skill_id')->nullable()->constrained()->nullOnDelete()->after('agent_id');
            $table->index(['skill_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->dropIndex(['skill_id', 'status']);
            $table->dropConstrainedForeignId('skill_id');
            $table->dropColumn('trigger');
        });
    }
};
