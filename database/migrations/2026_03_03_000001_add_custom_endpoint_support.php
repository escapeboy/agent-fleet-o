<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_provider_credentials', function (Blueprint $table) {
            $table->string('name')->nullable()->after('provider');
        });

        // Replace unique(team_id, provider) with unique(team_id, provider, name)
        // to allow multiple custom endpoints per team while keeping standard
        // providers (name=null) unique.
        Schema::table('team_provider_credentials', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'provider']);
            $table->unique(['team_id', 'provider', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('team_provider_credentials', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'provider', 'name']);
            $table->unique(['team_id', 'provider']);
        });

        Schema::table('team_provider_credentials', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
