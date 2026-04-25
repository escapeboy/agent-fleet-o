<?php

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->string('subkind', 64)->nullable()->after('type');
            $table->index('subkind', 'tools_subkind_idx');
        });

        // Backfill known integrations using a portable PHP iteration so the
        // migration runs identically on PostgreSQL (production) and SQLite (tests).
        // Currently only Boruna mcp_stdio tools are auto-tagged.
        Tool::withoutGlobalScopes()
            ->where('type', ToolType::McpStdio->value)
            ->whereNull('subkind')
            ->chunkById(200, function ($tools): void {
                foreach ($tools as $tool) {
                    $command = (string) ($tool->transport_config['command'] ?? '');
                    if ($command !== '' && stripos($command, 'boruna') !== false) {
                        $tool->subkind = 'boruna';
                        $tool->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropIndex('tools_subkind_idx');
            $table->dropColumn('subkind');
        });
    }
};
