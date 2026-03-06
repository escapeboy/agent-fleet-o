<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->foreignUuid('installed_email_theme_id')->nullable()->constrained('email_themes')->nullOnDelete();
            $table->foreignUuid('installed_email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installed_email_theme_id');
            $table->dropConstrainedForeignId('installed_email_template_id');
        });
    }
};
