<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\DeployToolTemplateAction;
use App\Domain\Tool\Enums\ToolTemplateCategory;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\ToolTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function createTemplate(array $overrides = []): ToolTemplate
    {
        return ToolTemplate::create(array_merge([
            'slug' => 'test-ocr',
            'name' => 'Test OCR',
            'category' => ToolTemplateCategory::Ocr,
            'description' => 'Test OCR template',
            'icon' => '📄',
            'provider' => 'runpod',
            'docker_image' => 'vllm/vllm-openai:latest',
            'model_id' => 'test/model',
            'default_input_schema' => ['type' => 'object'],
            'default_output_schema' => ['type' => 'object'],
            'deploy_config' => ['gpu_type' => 'NVIDIA RTX 4090', 'min_workers' => 0],
            'tool_definitions' => [['name' => 'ocr_extract', 'description' => 'Extract text']],
            'estimated_gpu' => 'NVIDIA RTX 4090',
            'estimated_cost_per_hour' => 440,
            'source_url' => 'https://example.com',
            'license' => 'Apache-2.0',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 10,
        ], $overrides));
    }

    public function test_template_has_correct_category_enum(): void
    {
        $template = $this->createTemplate();

        $this->assertInstanceOf(ToolTemplateCategory::class, $template->category);
        $this->assertEquals(ToolTemplateCategory::Ocr, $template->category);
        $this->assertEquals('OCR / Document Processing', $template->category->label());
    }

    public function test_template_estimated_cost_display(): void
    {
        $template = $this->createTemplate(['estimated_cost_per_hour' => 440]);
        $this->assertEquals('~$0.44/hr GPU', $template->estimatedCostDisplay());

        $free = $this->createTemplate(['slug' => 'free-tool', 'estimated_cost_per_hour' => 0]);
        $this->assertEquals('Free (API-based)', $free->estimatedCostDisplay());
    }

    public function test_active_scope_filters_correctly(): void
    {
        $this->createTemplate(['slug' => 'active-one', 'is_active' => true]);
        $this->createTemplate(['slug' => 'inactive-one', 'is_active' => false]);

        $active = ToolTemplate::active()->get();
        $this->assertCount(1, $active);
        $this->assertEquals('active-one', $active->first()->slug);
    }

    public function test_featured_scope_works(): void
    {
        $this->createTemplate(['slug' => 'feat-1', 'is_featured' => true]);
        $this->createTemplate(['slug' => 'feat-2', 'is_featured' => false]);

        $featured = ToolTemplate::featured()->get();
        $this->assertCount(1, $featured);
    }

    public function test_deploy_action_creates_tool_from_template(): void
    {
        $team = Team::factory()->create();
        $template = $this->createTemplate();

        $action = app(DeployToolTemplateAction::class);
        $tool = $action->execute(
            teamId: $team->id,
            template: $template,
            endpointId: 'ep-123',
        );

        $this->assertEquals($team->id, $tool->team_id);
        $this->assertEquals(ToolType::ComputeEndpoint, $tool->type);
        $this->assertEquals('Test OCR', $tool->name);
        $this->assertEquals('active', $tool->status->value);
        $this->assertEquals('runpod', $tool->transport_config['provider']);
        $this->assertEquals('ep-123', $tool->transport_config['endpoint_id']);
        $this->assertEquals('test-ocr', $tool->transport_config['template_slug']);
        $this->assertCount(1, $tool->tool_definitions);
    }

    public function test_deploy_without_endpoint_creates_disabled_tool(): void
    {
        $team = Team::factory()->create();
        $template = $this->createTemplate();

        $action = app(DeployToolTemplateAction::class);
        $tool = $action->execute(
            teamId: $team->id,
            template: $template,
        );

        $this->assertEquals('disabled', $tool->status->value);
        $this->assertNull($tool->transport_config['endpoint_id']);
    }

    public function test_deploy_with_provider_override(): void
    {
        $team = Team::factory()->create();
        $template = $this->createTemplate();

        $action = app(DeployToolTemplateAction::class);
        $tool = $action->execute(
            teamId: $team->id,
            template: $template,
            provider: 'replicate',
            endpointId: 'rep-456',
        );

        $this->assertEquals('replicate', $tool->transport_config['provider']);
        $this->assertContains('replicate', $tool->tags);
    }

    public function test_all_template_categories_have_labels_and_icons(): void
    {
        foreach (ToolTemplateCategory::cases() as $cat) {
            $this->assertNotEmpty($cat->label());
            $this->assertNotEmpty($cat->icon());
        }
    }
}
