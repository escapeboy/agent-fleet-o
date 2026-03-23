<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('ocsf_class_uid')->nullable()->after('event');
            $table->unsignedTinyInteger('ocsf_severity_id')->default(1)->after('ocsf_class_uid');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropColumn(['ocsf_class_uid', 'ocsf_severity_id']);
        });
    }
};
