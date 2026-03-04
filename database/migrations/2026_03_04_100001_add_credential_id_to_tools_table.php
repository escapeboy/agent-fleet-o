<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->foreignUuid('credential_id')
                ->nullable()
                ->after('team_id')
                ->constrained('credentials')
                ->nullOnDelete();

            $table->index(['team_id', 'credential_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'credential_id']);
            $table->dropConstrainedForeignId('credential_id');
        });
    }
};
