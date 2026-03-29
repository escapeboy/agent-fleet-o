<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table): void {
            $table->jsonb('context_scope')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('crew_members', function (Blueprint $table): void {
            $table->dropColumn('context_scope');
        });
    }
};
