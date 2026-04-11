<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Agent\Pipeline\Middleware\DetectClarificationNeeded;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DetectClarificationFormSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test team '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $this->agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [
                'clarification_detection_enabled' => true,
                'clarification_threshold' => 0.5,
            ],
        ]);
    }

    public function test_sanitizes_radio_cards_schema_from_llm_response(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'Approve or reject?',
            'form_schema' => [
                'fields' => [
                    [
                        'name' => 'decision',
                        'label' => 'Decision',
                        'type' => 'radio_cards',
                        'required' => true,
                        'options' => [
                            ['value' => 'approve', 'label' => 'Approve'],
                            ['value' => 'reject', 'label' => 'Reject'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($ctx->requiresClarification);
        $this->assertSame('Approve or reject?', $ctx->clarificationQuestion);
        $this->assertNotNull($ctx->clarificationFormSchema);
        $this->assertCount(1, $ctx->clarificationFormSchema['fields']);

        $field = $ctx->clarificationFormSchema['fields'][0];
        $this->assertSame('decision', $field['name']);
        $this->assertSame('radio_cards', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertCount(2, $field['options']);
        $this->assertSame('approve', $field['options'][0]['value']);
    }

    public function test_drops_unknown_field_types(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'What next?',
            'form_schema' => [
                'fields' => [
                    ['name' => 'evil', 'type' => 'iframe', 'label' => 'x'],
                    ['name' => 'good', 'type' => 'textarea', 'label' => 'ok'],
                ],
            ],
        ]);

        $this->assertNotNull($ctx->clarificationFormSchema);
        $this->assertCount(1, $ctx->clarificationFormSchema['fields']);
        $this->assertSame('good', $ctx->clarificationFormSchema['fields'][0]['name']);
    }

    public function test_strips_html_tags_from_labels_and_help(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'x',
            'form_schema' => [
                'fields' => [
                    [
                        'name' => 'answer',
                        'label' => 'Name <script>alert("xss")</script>',
                        'help' => '<img src=x onerror=alert(1)>hint',
                        'type' => 'text',
                    ],
                ],
            ],
        ]);

        $field = $ctx->clarificationFormSchema['fields'][0];
        $this->assertStringNotContainsString('<script>', $field['label']);
        $this->assertStringNotContainsString('onerror', $field['help']);
        $this->assertStringContainsString('Name', $field['label']);
    }

    public function test_number_field_preserves_min_max_only_when_numeric(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'Budget?',
            'form_schema' => [
                'fields' => [
                    ['name' => 'budget', 'type' => 'number', 'min' => 0, 'max' => 'lots', 'label' => 'Budget'],
                ],
            ],
        ]);

        $field = $ctx->clarificationFormSchema['fields'][0];
        $this->assertSame('number', $field['type']);
        $this->assertSame(0.0, $field['min']);
        $this->assertArrayNotHasKey('max', $field);
    }

    public function test_select_with_no_valid_options_degrades_to_textarea(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'x',
            'form_schema' => [
                'fields' => [
                    ['name' => 'choice', 'type' => 'select', 'label' => 'Pick', 'options' => []],
                ],
            ],
        ]);

        $this->assertSame('textarea', $ctx->clarificationFormSchema['fields'][0]['type']);
    }

    public function test_field_count_capped_at_six(): void
    {
        $fields = [];
        for ($i = 0; $i < 20; $i++) {
            $fields[] = ['name' => "f{$i}", 'type' => 'text', 'label' => "F {$i}"];
        }

        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'x',
            'form_schema' => ['fields' => $fields],
        ]);

        $this->assertLessThanOrEqual(6, count($ctx->clarificationFormSchema['fields']));
    }

    public function test_option_count_capped_at_twenty(): void
    {
        $options = [];
        for ($i = 0; $i < 50; $i++) {
            $options[] = ['value' => "v{$i}", 'label' => "Option {$i}"];
        }

        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'Pick one',
            'form_schema' => [
                'fields' => [
                    ['name' => 'pick', 'type' => 'select', 'label' => 'Pick', 'options' => $options],
                ],
            ],
        ]);

        $this->assertLessThanOrEqual(20, count($ctx->clarificationFormSchema['fields'][0]['options']));
    }

    public function test_field_name_is_sluggified(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'x',
            'form_schema' => [
                'fields' => [
                    ['name' => 'target audience!', 'type' => 'text', 'label' => 'Who'],
                ],
            ],
        ]);

        $this->assertSame('target_audience_', $ctx->clarificationFormSchema['fields'][0]['name']);
    }

    public function test_missing_form_schema_leaves_clarification_schema_null(): void
    {
        $ctx = $this->runMiddleware([
            'ambiguity_score' => 0.9,
            'question' => 'Clarify',
            // no form_schema key
        ]);

        $this->assertTrue($ctx->requiresClarification);
        $this->assertNull($ctx->clarificationFormSchema);
    }

    public function test_invalid_json_leaves_clarification_state_untouched(): void
    {
        $this->bindGateway('not valid json');

        $ctx = $this->makeContext();
        $next = fn ($c) => $c;
        $result = app(DetectClarificationNeeded::class)->handle($ctx, $next);

        $this->assertFalse($result->requiresClarification);
        $this->assertNull($result->clarificationFormSchema);
    }

    /**
     * Run the middleware with a mocked gateway response and return the mutated context.
     */
    private function runMiddleware(array $llmResponse): AgentExecutionContext
    {
        $this->bindGateway(json_encode($llmResponse));

        $ctx = $this->makeContext();
        $next = fn ($c) => $c;

        return app(DetectClarificationNeeded::class)->handle($ctx, $next);
    }

    private function bindGateway(string $responseContent): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $responseContent,
            parsedOutput: [],
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 10,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);
        $this->app->instance(ProviderResolver::class, $resolver);
    }

    private function makeContext(): AgentExecutionContext
    {
        return new AgentExecutionContext(
            agent: $this->agent,
            teamId: $this->team->id,
            userId: $this->team->owner_id,
            experimentId: null,
            project: null,
            input: ['goal' => 'Do something ambiguous'],
        );
    }
}
