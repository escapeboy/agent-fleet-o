<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->json('attribute_changes')->nullable()->after('event');

            if (Schema::hasColumn('activity_log', 'batch_uuid')) {
                $table->dropColumn('batch_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
            $table->uuid('batch_uuid')->nullable()->after('event');
        });
    }
};
