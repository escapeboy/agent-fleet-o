<?php

use App\Domain\Website\Models\Website;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('website_id')->nullable()->after('workflow_id')
                ->constrained('websites')->nullOnDelete();

            $table->index(
                ['team_id', 'website_id'],
                'projects_team_website_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_team_website_index');
            $table->dropForeignIdFor(Website::class, 'website_id');
            $table->dropColumn('website_id');
        });
    }
};
