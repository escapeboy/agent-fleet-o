<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Executors\HttpRequestNodeExecutor;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpRequestNodeExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass DNS resolution for example.test/partner.test fixtures.
        $mock = $this->createMock(SsrfGuard::class);
        $this->app->instance(SsrfGuard::class, $mock);
    }

    private function makeFixture(array $config, array $inputData = []): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'meta' => ['input_data' => $inputData],
        ]);

        $workflow = Workflow::factory()->create(['team_id' => $team->id]);

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::HttpRequest,
            'label' => 'HTTP',
            'config' => $config,
            'order' => 1,
        ]);

        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'name' => 'http call',
            'order' => 1,
            'status' => 'pending',
        ]);

        return [$node, $step, $experiment];
    }

    public function test_renders_body_against_input_context_from_signal_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        [$node, $step, $experiment] = $this->makeFixture(
            config: [
                'method' => 'POST',
                'url' => 'https://example.test/invoices',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"document_id": "{{input._signal_payload.document_id}}", "confidence": "{{input.confidence}}"}',
            ],
            inputData: [
                'confidence' => 0.97,
                '_signal_payload' => ['document_id' => 'doc-42'],
            ],
        );

        app(HttpRequestNodeExecutor::class)->execute($node, $step, $experiment);

        Http::assertSent(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://example.test/invoices', $request->url());
            $this->assertStringContainsString('"document_id": "doc-42"', $request->body());
            $this->assertStringContainsString('"confidence": "0.97"', $request->body());

            return true;
        });
    }

    public function test_signs_body_with_hmac_after_interpolation(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $secret = 'supersecret';
        $body = '{"document_id":"doc-42","confidence":0.97}';
        $expectedHex = hash_hmac('sha256', $body, $secret);

        [$node, $step, $experiment] = $this->makeFixture(
            config: [
                'method' => 'POST',
                'url' => 'https://partner.test/invoices',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
                'sign_with_hmac' => [
                    'secret' => $secret,
                    'header' => 'X-Fleetq-Signature',
                    'algo' => 'sha256',
                    'body_format' => 'sha256={hex}',
                ],
            ],
        );

        app(HttpRequestNodeExecutor::class)->execute($node, $step, $experiment);

        Http::assertSent(function ($request) use ($expectedHex) {
            $headers = $request->headers();
            $this->assertArrayHasKey('X-Fleetq-Signature', $headers);
            $this->assertSame("sha256={$expectedHex}", $headers['X-Fleetq-Signature'][0]);

            return true;
        });
    }

    public function test_skips_hmac_when_secret_empty(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        [$node, $step, $experiment] = $this->makeFixture(
            config: [
                'method' => 'POST',
                'url' => 'https://example.test/no-sign',
                'body' => 'hello',
                'sign_with_hmac' => [
                    'secret' => '',
                    'header' => 'X-Fleetq-Signature',
                ],
            ],
        );

        app(HttpRequestNodeExecutor::class)->execute($node, $step, $experiment);

        Http::assertSent(function ($request) {
            $this->assertArrayNotHasKey('X-Fleetq-Signature', $request->headers());

            return true;
        });
    }
}
