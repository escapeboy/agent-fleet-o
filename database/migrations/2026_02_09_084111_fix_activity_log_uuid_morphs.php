<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');

        // Change bigint morph columns to UUID-compatible varchar (PostgreSQL only;
        // SQLite gets nullableUuidMorphs which are already VARCHAR from the create migration)
        if (DB::getDriverName() === 'pgsql') {
            DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN subject_id TYPE varchar(36) USING subject_id::varchar");
            DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN causer_id TYPE varchar(36) USING causer_id::varchar");
        }
    }

    public function down(): void
    {
        $table = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');

        if (DB::getDriverName() === 'pgsql') {
            DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN subject_id TYPE bigint USING subject_id::bigint");
            DB::connection($connection)->statement("ALTER TABLE {$table} ALTER COLUMN causer_id TYPE bigint USING causer_id::bigint");
        }
    }
};
