<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Jobs\ClassifyAutoSignalJob;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\AutoBugReportClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Api\V1\ApiTestCase;

class ClassifyAutoSignalJobTest extends ApiTestCase
{
    use RefreshDatabase;

    private function makeSignal(array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'test-project',
            'project_key' => 'test-project',
            'reported_type' => 'auto',
            'payload' => [
                'title' => 'Бутонът не работи',
                'description' => 'Кликвам Запази и нищо не се случва.',
                'severity' => 'major',
            ],
            'content_hash' => hash('sha256', uniqid('triage-', true)),
            'received_at' => now(),
            'tags' => ['bug_report'],
            'status' => 'received',
        ], $overrides));
    }

    public function test_skips_when_reported_type_is_not_auto(): void
    {
        $classifier = Mockery::mock(AutoBugReportClassifier::class);
        $classifier->shouldNotReceive('classify');
        $this->app->instance(AutoBugReportClassifier::class, $classifier);

        $signal = $this->makeSignal(['reported_type' => 'bug']);

        (new ClassifyAutoSignalJob($signal->id))->handle($classifier);

        $this->assertNull($signal->fresh()->suggested_type);
    }

    public function test_skips_when_suggested_type_already_set(): void
    {
        $classifier = Mockery::mock(AutoBugReportClassifier::class);
        $classifier->shouldNotReceive('classify');
        $this->app->instance(AutoBugReportClassifier::class, $classifier);

        $signal = $this->makeSignal(['suggested_type' => 'bug', 'suggested_type_confidence' => 0.9]);

        (new ClassifyAutoSignalJob($signal->id))->handle($classifier);

        $this->assertSame('bug', $signal->fresh()->suggested_type);
        $this->assertDatabaseCount('signal_comments', 0);
    }

    public function test_happy_path_writes_suggested_type_and_agent_comment(): void
    {
        $classifier = Mockery::mock(AutoBugReportClassifier::class);
        $classifier->shouldReceive('classify')->once()->andReturn([
            'classified_type' => 'bug',
            'confidence' => 0.88,
            'rationale' => 'Функцията не работи.',
        ]);
        $this->app->instance(AutoBugReportClassifier::class, $classifier);

        $signal = $this->makeSignal();

        (new ClassifyAutoSignalJob($signal->id))->handle($classifier);

        $fresh = $signal->fresh();
        $this->assertSame('bug', $fresh->suggested_type);
        $this->assertEqualsWithDelta(0.88, $fresh->suggested_type_confidence, 0.001);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'author_type' => 'agent',
            'widget_visible' => false,
            'idempotency_key' => "triage:classify:{$signal->id}",
        ]);
    }

    public function test_classifier_throws_leaves_signal_unannotated_and_no_comment(): void
    {
        $classifier = Mockery::mock(AutoBugReportClassifier::class);
        $classifier->shouldReceive('classify')->once()->andThrow(new \RuntimeException('LLM down'));
        $this->app->instance(AutoBugReportClassifier::class, $classifier);

        Log::shouldReceive('warning')->once()->withArgs(function (string $msg) {
            return str_contains($msg, 'ClassifyAutoSignalJob');
        });

        $signal = $this->makeSignal();

        (new ClassifyAutoSignalJob($signal->id))->handle($classifier);

        $this->assertNull($signal->fresh()->suggested_type);
        $this->assertDatabaseCount('signal_comments', 0);
    }
}
