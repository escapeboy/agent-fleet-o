<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add execution_profile JSONB column to marketplace_listings.
     *
     * The execution profile captures the sandbox and capability requirements
     * of a published item (e.g. requires_bash, network_destinations, etc.)
     * so that marketplace consumers can make informed installation decisions.
     */
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->jsonb('execution_profile')->nullable()->after('configuration_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropColumn('execution_profile');
        });
    }
};
