<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            // N-of-M quorum. Default 1 preserves single-approver behavior for
            // every existing caller.
            $table->unsignedSmallInteger('required_approvals')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropColumn('required_approvals');
        });
    }
};
