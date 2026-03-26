<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->foreignUuid('parent_version_id')->nullable()->constrained('skill_versions')->nullOnDelete()->after('skill_id');
            $table->string('evolution_type')->default('manual')->after('parent_version_id'); // initial|fix|derived|captured|manual
        });
    }

    public function down(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_version_id');
            $table->dropColumn('evolution_type');
        });
    }
};
