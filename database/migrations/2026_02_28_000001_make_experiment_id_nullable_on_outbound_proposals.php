<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes experiment_id nullable on outbound_proposals so that system-generated
 * messages (e.g. connector pairing code replies) can be sent without being
 * tied to an experiment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in tests) doesn't support DROP FOREIGN KEY
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('outbound_proposals', function (Blueprint $table) {
                $table->dropForeign(['experiment_id']);
            });
        }

        Schema::table('outbound_proposals', function (Blueprint $table) {
            $table->uuid('experiment_id')->nullable()->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('outbound_proposals', function (Blueprint $table) {
                $table->foreign('experiment_id')->references('id')->on('experiments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('outbound_proposals', function (Blueprint $table) {
                $table->dropForeign(['experiment_id']);
            });
        }

        Schema::table('outbound_proposals', function (Blueprint $table) {
            $table->uuid('experiment_id')->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('outbound_proposals', function (Blueprint $table) {
                $table->foreign('experiment_id')->references('id')->on('experiments')->cascadeOnDelete();
            });
        }
    }
};
