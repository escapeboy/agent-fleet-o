<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('tier')->default('working')->after('visibility');
            $table->string('proposed_by')->nullable()->after('tier');

            // Index for filtering by tier (e.g. listing all proposed memories for review)
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn(['tier', 'proposed_by']);
        });
    }
};
