<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category'); // ocr, stt, tts, image_generation, video, embedding, custom
            $table->text('description');
            $table->string('icon')->nullable(); // emoji or icon class
            $table->string('provider')->default('runpod'); // compute provider slug
            $table->string('docker_image'); // e.g. "vllm/vllm-openai:latest"
            $table->string('model_id')->nullable(); // e.g. "stepfun-ai/GLM-OCR-2B" for vLLM
            $table->jsonb('default_input_schema')->default('{}'); // expected input format
            $table->jsonb('default_output_schema')->default('{}'); // expected output format
            $table->jsonb('deploy_config')->default('{}'); // gpu_type, min_workers, env vars, etc.
            $table->jsonb('tool_definitions')->default('[]'); // pre-built PrismPHP tool definitions
            $table->string('estimated_gpu')->nullable(); // recommended GPU, e.g. "RTX 4090"
            $table->integer('estimated_cost_per_hour')->default(0); // platform credits/hr
            $table->string('source_url')->nullable(); // link to model/project
            $table->string('license')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_templates');
    }
};
