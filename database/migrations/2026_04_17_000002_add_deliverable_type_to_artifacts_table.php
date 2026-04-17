<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->string('deliverable_type', 40)->nullable()->after('type');
            $table->index(['team_id', 'deliverable_type']);
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'deliverable_type']);
            $table->dropColumn('deliverable_type');
        });
    }
};
