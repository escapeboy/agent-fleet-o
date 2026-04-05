<?php

use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignUuid('crew_execution_id')->nullable()->after('settings')
                ->constrained('crew_executions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeignIdFor(CrewExecution::class, 'crew_execution_id');
            $table->dropColumn('crew_execution_id');
        });
    }
};
