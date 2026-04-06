<?php

use App\Domain\Crew\Models\Crew;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignUuid('managing_crew_id')->nullable()->after('crew_execution_id')
                ->constrained('crews')->nullOnDelete();

            $table->index(
                ['team_id', 'managing_crew_id'],
                'websites_team_managing_crew_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex('websites_team_managing_crew_index');
            $table->dropForeignIdFor(Crew::class, 'managing_crew_id');
            $table->dropColumn('managing_crew_id');
        });
    }
};
