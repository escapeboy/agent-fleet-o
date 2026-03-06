<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft'); // draft, active, archived
            // Brand identity
            $table->string('logo_url')->nullable();
            $table->unsignedSmallInteger('logo_width')->default(150);
            // Colors
            $table->string('background_color', 7)->default('#f4f4f4');
            $table->string('canvas_color', 7)->default('#ffffff');
            $table->string('primary_color', 7)->default('#2563eb');
            $table->string('text_color', 7)->default('#1f2937');
            $table->string('heading_color', 7)->default('#111827');
            $table->string('muted_color', 7)->default('#6b7280');
            $table->string('divider_color', 7)->default('#e5e7eb');
            // Typography
            $table->string('font_name')->default('Inter');
            $table->string('font_url')->nullable();
            $table->string('font_family')->default('Inter, Arial, sans-serif');
            $table->unsignedSmallInteger('heading_font_size')->default(24);
            $table->unsignedSmallInteger('body_font_size')->default(16);
            $table->decimal('line_height', 3, 1)->default(1.6);
            // Layout
            $table->unsignedSmallInteger('email_width')->default(600);
            $table->unsignedSmallInteger('content_padding')->default(24);
            // Footer
            $table->string('company_name')->nullable();
            $table->text('company_address')->nullable();
            $table->text('footer_text')->nullable();
            // Behavior
            $table->boolean('is_system_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_themes');
    }
};
