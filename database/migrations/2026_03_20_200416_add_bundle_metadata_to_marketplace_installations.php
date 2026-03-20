<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->jsonb('bundle_metadata')->nullable()->after('installed_email_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->dropColumn('bundle_metadata');
        });
    }
};
