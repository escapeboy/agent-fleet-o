<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Actions\StructureSignalWithAiAction;
use App\Domain\Signal\Jobs\StructureBugReportJob;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Api\V1\ApiTestCase;

class StructureBugReportJobTest extends ApiTestCase
{
    use RefreshDatabase;

    private function makeSignal(array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'test-project',
            'project_key' => 'test-project',
            'payload' => [
                'description' => 'When I click submit the page throws a 500 error and the form is lost.',
                'title' => 'Submit error',
            ],
            'content_hash' => hash('sha256', uniqid('struct-', true)),
            'received_at' => now(),
            'tags' => ['bug_report'],
            'status' => 'received',
        ], $overrides));
    }

    public function test_flag_off_makes_no_llm_call(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => false]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        $structurer->shouldNotReceive('execute');
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        $signal = $this->makeSignal();

        (new StructureBugReportJob($signal->id))->handle($structurer);

        $this->assertArrayNotHasKey('ai_structured', $signal->fresh()->metadata ?? []);
    }

    public function test_flag_on_short_description_makes_no_llm_call(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => true]);
        config(['signals.bug_report.structured_intake_min_chars' => 20]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        $structurer->shouldNotReceive('execute');
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        $signal = $this->makeSignal(['payload' => ['description' => 'short', 'title' => 'x']]);

        (new StructureBugReportJob($signal->id))->handle($structurer);

        $this->assertArrayNotHasKey('ai_structured', $signal->fresh()->metadata ?? []);
    }

    public function test_flag_on_valid_description_populates_metadata(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => true]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        $structurer->shouldReceive('execute')->once()->andReturn([
            'title' => 'Submit 500',
            'description' => 'Submit throws 500',
            'priority' => 'high',
            'tags' => ['submit', 'form'],
            'source_type' => 'bug_report',
            'metadata' => [
                'steps_to_reproduce' => '1. Go to form 2. Click submit',
                'component' => 'CheckoutForm',
                'affected_user' => 'alice@example.com',
            ],
        ]);
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        $signal = $this->makeSignal();

        (new StructureBugReportJob($signal->id))->handle($structurer);

        $fresh = $signal->fresh();
        $this->assertTrue($fresh->metadata['ai_structured']);
        $this->assertEquals(['submit', 'form'], $fresh->metadata['ai_tags']);
        $this->assertEquals('high', $fresh->metadata['ai_priority']);
        $this->assertEquals('1. Go to form 2. Click submit', $fresh->metadata['ai_extracted']['steps_to_reproduce']);
        $this->assertEquals('CheckoutForm', $fresh->metadata['ai_extracted']['component']);
    }

    public function test_flag_on_gateway_throws_signal_unchanged(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => true]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        $structurer->shouldReceive('execute')->once()->andThrow(new \RuntimeException('gateway down'));
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        Log::shouldReceive('warning')->once()->withArgs(function ($msg) {
            return str_contains($msg, 'StructureBugReportJob');
        });
        Log::shouldReceive('info')->never();

        $signal = $this->makeSignal();
        $originalMetadata = $signal->metadata;

        (new StructureBugReportJob($signal->id))->handle($structurer);

        $this->assertEquals($originalMetadata, $signal->fresh()->metadata);
    }

    public function test_signal_not_found_returns_silently(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => true]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        $structurer->shouldNotReceive('execute');
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        // No exception — just a silent return
        (new StructureBugReportJob('00000000-0000-0000-0000-000000000000'))->handle($structurer);

        $this->assertTrue(true); // reached without exception
    }

    public function test_malformed_structurer_response_leaves_signal_unchanged(): void
    {
        config(['signals.bug_report.structured_intake_enabled' => true]);

        $structurer = Mockery::mock(StructureSignalWithAiAction::class);
        // Return a response missing expected keys
        $structurer->shouldReceive('execute')->once()->andReturn([
            'title' => 'ok',
            // tags / metadata absent
        ]);
        $this->app->instance(StructureSignalWithAiAction::class, $structurer);

        $signal = $this->makeSignal();

        (new StructureBugReportJob($signal->id))->handle($structurer);

        $fresh = $signal->fresh();
        // Should still set ai_structured but with empty derived values — not crash
        $this->assertTrue($fresh->metadata['ai_structured']);
        $this->assertEquals([], $fresh->metadata['ai_tags']);
        $this->assertEquals([], $fresh->metadata['ai_extracted']);
    }
}
