<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->jsonb('allowed_domains')->nullable()->after('metadata');
            $table->index('allowed_domains', 'credentials_allowed_domains_gin', 'gin');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('credentials_allowed_domains_gin');
            $table->dropColumn('allowed_domains');
        });
    }
};
