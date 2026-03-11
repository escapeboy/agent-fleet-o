<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->string('creator_source')->default('human')->after('team_id');
            $table->nullableUuidMorphs('creator');
            $table->index(['team_id', 'creator_source']);
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'creator_source']);
            $table->dropMorphs('creator');
            $table->dropColumn('creator_source');
        });
    }
};
