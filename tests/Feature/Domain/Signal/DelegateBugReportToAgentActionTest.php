<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Actions\DelegateBugReportToAgentAction;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Api\V1\ApiTestCase;

class DelegateBugReportToAgentActionTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();
    }

    private function makeSignal(array $payload = [], array $metadata = []): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'my-project',
            'project_key' => 'my-project',
            'payload' => array_merge([
                'title' => 'Button broken',
                'description' => 'The submit button does nothing on the checkout page.',
                'severity' => 'major',
                'url' => 'https://app.example.com/checkout',
            ], $payload),
            'content_hash' => hash('sha256', uniqid('delegate-', true)),
            'received_at' => now(),
            'metadata' => $metadata ?: null,
            'tags' => ['bug_report'],
            'status' => 'received',
        ]);
    }

    /** Builds a DelegateBugReportToAgentAction that captures the thesis via a mock. */
    private function actionWithThesisCapture(?string &$capturedThesis): DelegateBugReportToAgentAction
    {
        $fakeExperiment = Experiment::factory()->make([
            'team_id' => $this->team->id,
            'thesis' => '',
        ]);

        $createExperiment = Mockery::mock(CreateExperimentAction::class);
        $createExperiment->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function (string $userId, string $title, string $thesis) use ($fakeExperiment, &$capturedThesis) {
                $capturedThesis = $thesis;
                $fakeExperiment->thesis = $thesis;

                return $fakeExperiment;
            });

        $updateStatus = Mockery::mock(UpdateSignalStatusAction::class);
        $updateStatus->shouldReceive('execute')->once();

        return new DelegateBugReportToAgentAction($createExperiment, $updateStatus);
    }

    public function test_signal_with_ai_extracted_adds_structured_sections_to_thesis(): void
    {
        $signal = $this->makeSignal([], [
            'ai_structured' => true,
            'ai_tags' => ['checkout', 'submit'],
            'ai_priority' => 'high',
            'ai_extracted' => [
                'steps_to_reproduce' => '1. Open checkout 2. Click Submit',
                'affected_user' => 'alice@example.com',
                'component' => 'CheckoutForm',
            ],
        ]);

        $thesis = null;
        $action = $this->actionWithThesisCapture($thesis);
        $action->execute($signal, $this->user);

        $this->assertStringContainsString('Steps to reproduce (AI-extracted)', $thesis);
        $this->assertStringContainsString('1. Open checkout 2. Click Submit', $thesis);
        $this->assertStringContainsString('**Affected user:** alice@example.com', $thesis);
        $this->assertStringContainsString('**Component:** CheckoutForm', $thesis);
        $this->assertStringContainsString('**Tags:** checkout, submit', $thesis);
    }

    public function test_signal_without_ai_extracted_thesis_unchanged(): void
    {
        $signal = $this->makeSignal();

        $thesis = null;
        $action = $this->actionWithThesisCapture($thesis);
        $action->execute($signal, $this->user);

        $this->assertStringNotContainsString('AI-extracted', $thesis);
        $this->assertStringNotContainsString('Affected user:', $thesis);
        $this->assertStringContainsString('Bug Report:', $thesis);
        $this->assertStringContainsString('Description:', $thesis);
    }

    public function test_ai_extracted_with_injection_attempt_gets_sanitized(): void
    {
        // Use only JSONB-safe non-printable chars (\x00 is rejected by PostgreSQL JSONB).
        // \x1B (ESC) is storable in JSONB and representative of ANSI escape injection.
        $signal = $this->makeSignal([], [
            'ai_structured' => true,
            'ai_tags' => [],
            'ai_priority' => 'medium',
            'ai_extracted' => [
                'steps_to_reproduce' => "Safe text\x1B[31m injected ANSI",
                'component' => 'Normal',
            ],
        ]);

        $thesis = null;
        $action = $this->actionWithThesisCapture($thesis);
        $action->execute($signal, $this->user);

        // \x1B (ESC) is below 0x20 and stripped by sanitize()
        $this->assertStringNotContainsString("\x1B", $thesis);
        // Printable content still present
        $this->assertStringContainsString('Safe text', $thesis);
    }
}
