<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_agents', function (Blueprint $table): void {
            $table->string('agent_address', 66)->nullable()->after('slug');
            $table->string('adapter_kind', 24)->default('http')->after('agent_address');
            $table->index('agent_address');
        });
    }

    public function down(): void
    {
        Schema::table('external_agents', function (Blueprint $table): void {
            $table->dropIndex(['agent_address']);
            $table->dropColumn(['agent_address', 'adapter_kind']);
        });
    }
};
