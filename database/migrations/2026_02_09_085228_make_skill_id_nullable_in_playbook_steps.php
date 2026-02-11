<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE playbook_steps ALTER COLUMN skill_id DROP NOT NULL');
        } else {
            Schema::table('playbook_steps', function (Blueprint $table) {
                $table->foreignUuid('skill_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE playbook_steps ALTER COLUMN skill_id SET NOT NULL');
        } else {
            Schema::table('playbook_steps', function (Blueprint $table) {
                $table->foreignUuid('skill_id')->nullable(false)->change();
            });
        }
    }
};
