<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table): void {
            $table->foreignUuid('external_agent_id')->nullable()->after('agent_id')
                ->constrained('external_agents')->nullOnDelete();
            $table->string('member_kind', 16)->default('internal')->after('external_agent_id');
        });

        // Make agent_id nullable so external members don't need an internal Agent row
        if (Schema::hasColumn('crew_members', 'agent_id')) {
            Schema::table('crew_members', function (Blueprint $table): void {
                $table->foreignUuid('agent_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('crew_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_agent_id');
            $table->dropColumn(['member_kind']);
        });
    }
};
