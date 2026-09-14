<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\BuiltIn\ExecuteCodeHandler;
use App\Domain\Tool\Services\ToolProgramContext;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * Covers `run_tool_program` (OpenAI Agents API "programmatic tool calling"
 * borrow): flag gating, late binding, batch dispatch through sibling
 * closures, the error paths the model self-corrects from, the recursion
 * guard, and the Python post-processing hand-off.
 */
class ToolTranslatorToolProgramTest extends TestCase
{
    use RefreshDatabase;

    private function programTool(): Tool
    {
        return Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'programmatic_tool_calling'],
        ]);
    }

    /**
     * @return array{0: PrismToolObject, 1: ToolProgramContext}
     */
    private function boundProgramTool(?SandboxedWorkspace $workspace = null, bool $withExecuteCode = true): array
    {
        config(['agent.programmatic_tool_calling.enabled' => true]);

        $context = new ToolProgramContext;
        $tools = app(ToolTranslator::class)->toPrismTools($this->programTool(), [], null, $workspace, null, $context);

        $echo = PrismTool::as('echo_tool')
            ->for('echoes')
            ->withStringParameter('text', 'text')
            ->using(fn (string $text): string => 'echo:'.$text);

        $boom = PrismTool::as('boom_tool')
            ->for('throws')
            ->withStringParameter('text', 'text')
            ->withoutErrorHandling()
            ->using(function (string $text): string {
                throw new \RuntimeException('kaboom '.$text);
            });

        $loud = PrismTool::as('loud_error_tool')
            ->for('fails with a huge body, like an MCP/HTTP tool returning an error page')
            ->withStringParameter('text', 'text')
            ->withoutErrorHandling()
            ->using(function (string $text): string {
                throw new \RuntimeException(str_repeat('E', 200_000));
            });

        $siblings = [$echo, $boom, $loud];
        if ($withExecuteCode) {
            // Only its presence matters: post_process is gated on it.
            $siblings[] = PrismTool::as('execute_code')
                ->for('runs python')
                ->withStringParameter('code', 'code')
                ->using(fn (string $code): string => 'not called in these tests');
        }

        $context->bind([...$tools, ...$siblings]);

        return [$tools[0], $context];
    }

    public function test_disabled_flag_returns_no_tool(): void
    {
        config(['agent.programmatic_tool_calling.enabled' => false]);

        $this->assertSame([], app(ToolTranslator::class)->toPrismTools($this->programTool()));
    }

    public function test_enabled_flag_exposes_run_tool_program(): void
    {
        config(['agent.programmatic_tool_calling.enabled' => true]);

        $tools = app(ToolTranslator::class)->toPrismTools($this->programTool());

        $this->assertCount(1, $tools);
        $this->assertSame('run_tool_program', $tools[0]->name());
    }

    public function test_unbound_context_fails_closed(): void
    {
        config(['agent.programmatic_tool_calling.enabled' => true]);

        $tools = app(ToolTranslator::class)->toPrismTools($this->programTool());
        $out = json_decode($tools[0]->handle(json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'x']]])), true);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('no bound tools', $out['error']);
    }

    public function test_runs_batch_in_order_through_sibling_tools(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle(json_encode([
            ['tool' => 'echo_tool', 'arguments' => ['text' => 'one']],
            ['tool' => 'echo_tool', 'arguments' => ['text' => 'two']],
        ])), true);

        $this->assertTrue($out['ok']);
        $this->assertSame(['echo:one', 'echo:two'], array_column($out['calls'], 'output'));
        $this->assertSame([0, 1], array_column($out['calls'], 'index'));
        $this->assertArrayNotHasKey('arguments', $out['calls'][0]);
    }

    public function test_unknown_tool_fails_that_call_and_continues(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle(json_encode([
            ['tool' => 'nope', 'arguments' => []],
            ['tool' => 'echo_tool', 'arguments' => ['text' => 'still runs']],
        ])), true);

        $this->assertFalse($out['calls'][0]['ok']);
        $this->assertStringContainsString("Unknown tool 'nope'", $out['calls'][0]['error']);
        $this->assertContains('echo_tool', $out['available_tools']);
        $this->assertNotContains('run_tool_program', $out['available_tools']);
        $this->assertSame('echo:still runs', $out['calls'][1]['output']);
    }

    public function test_throwing_sibling_is_reported_not_propagated(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle(json_encode([
            ['tool' => 'boom_tool', 'arguments' => ['text' => 'a']],
            ['tool' => 'echo_tool', 'arguments' => ['text' => 'b']],
        ])), true);

        $this->assertFalse($out['calls'][0]['ok']);
        $this->assertStringContainsString('kaboom a', $out['calls'][0]['error']);
        $this->assertTrue($out['calls'][1]['ok']);
    }

    public function test_recursion_into_itself_is_refused(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle(json_encode([
            ['tool' => 'run_tool_program', 'arguments' => ['calls' => '[]']],
        ])), true);

        $this->assertFalse($out['calls'][0]['ok']);
        $this->assertStringContainsString('Unknown tool', $out['calls'][0]['error']);
    }

    public function test_invalid_calls_payloads_return_actionable_errors(): void
    {
        [$program] = $this->boundProgramTool();
        config(['agent.programmatic_tool_calling.max_calls' => 2]);
        [$program] = $this->boundProgramTool();

        $cases = [
            'not json' => 'non-empty JSON array',
            '[]' => 'non-empty JSON array',
            '{"tool":"echo_tool"}' => 'non-empty JSON array',
            '[{"arguments":{}}]' => 'calls[0].tool must be',
            '[{"tool":"echo_tool","arguments":"x"}]' => 'calls[0].arguments must be',
            '[{"tool":"echo_tool","arguments":["x"]}]' => 'calls[0].arguments must be',
            '[{"tool":"echo_tool","arguments":{"3":"x"}}]' => 'calls[0].arguments must be',
            '[{"tool":"echo_tool","arguments":{"text":"a","0":"b"}}]' => 'calls[0].arguments must be',
            '[{"tool":"a"},{"tool":"b"},{"tool":"c"}]' => 'the maximum is 2',
        ];

        foreach ($cases as $payload => $expected) {
            $out = json_decode($program->handle($payload), true);
            $this->assertFalse($out['ok'], $payload);
            $this->assertStringContainsString($expected, $out['error'], $payload);
        }
    }

    public function test_strips_markdown_fence_around_calls(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle("```json\n[{\"tool\":\"echo_tool\",\"arguments\":{\"text\":\"fenced\"}}]\n```"), true);

        $this->assertSame('echo:fenced', $out['calls'][0]['output']);
    }

    public function test_raw_results_are_truncated_to_max_output_chars(): void
    {
        [$program] = $this->boundProgramTool();

        $out = json_decode($program->handle(
            json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => str_repeat('x', 5000)]]]),
            null,
            600,
        ), true);

        $this->assertStringContainsString('[truncated', $out['calls'][0]['output']);
        $this->assertLessThan(700, mb_strlen($out['calls'][0]['output']));
    }

    public function test_model_cannot_raise_max_output_chars_above_config(): void
    {
        config(['agent.programmatic_tool_calling.max_output_chars' => 1000]);
        [$program] = $this->boundProgramTool();

        $calls = json_encode(array_fill(0, 5, ['tool' => 'echo_tool', 'arguments' => ['text' => str_repeat('y', 50_000)]]));
        $out = $program->handle($calls, null, 1e8);

        // 5 calls × (1000/5 chars + marker) + JSON envelope; far below the 250k raw.
        $this->assertLessThan(3_000, mb_strlen($out));
    }

    public function test_huge_error_bodies_respect_the_output_cap(): void
    {
        [$program] = $this->boundProgramTool();
        $cap = (int) config('agent.programmatic_tool_calling.max_output_chars');

        $out = $program->handle(json_encode(array_fill(0, 25, ['tool' => 'loud_error_tool', 'arguments' => ['text' => 'x']])));

        $this->assertLessThanOrEqual($cap, mb_strlen($out));
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'must stay valid JSON while under the cap');
        $this->assertFalse($decoded['calls'][0]['ok']);
    }

    public function test_single_huge_error_is_truncated_not_dropped(): void
    {
        [$program] = $this->boundProgramTool();

        $out = $program->handle(json_encode([['tool' => 'loud_error_tool', 'arguments' => ['text' => 'x']]]), null, 500);

        $this->assertLessThanOrEqual(500, mb_strlen($out));
        $decoded = json_decode($out, true);
        $this->assertStringStartsWith('EEEE', $decoded['calls'][0]['error']);
        $this->assertStringEndsWith('[truncated]', $decoded['calls'][0]['error']);
    }

    public function test_many_unknown_tools_at_lowest_cap_stay_within_cap(): void
    {
        [$program] = $this->boundProgramTool();

        $out = $program->handle(json_encode(array_fill(0, 25, ['tool' => 'nope', 'arguments' => []])), null, 200);

        // 25 status rows cannot fit 200 chars as JSON; the hard ceiling still holds.
        $this->assertLessThanOrEqual(200, mb_strlen($out));
    }

    public function test_exception_inside_the_program_is_capped_and_does_not_echo_code(): void
    {
        [$program] = $this->boundProgramTool();

        $this->mock(ExecuteCodeHandler::class, function ($mock) {
            // Laravel's process timeout message embeds the full command, i.e. the model's code.
            $mock->shouldReceive('execute')->once()->andThrow(new \RuntimeException('timed out: python3 -c '.str_repeat('X', 100_000)));
        });

        $out = $program->handle(json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'a']]]), 'print("SECRET_CODE")', 200);
        $text = $out instanceof ToolError ? $out->message : $out;

        $this->assertLessThanOrEqual(200, mb_strlen($text));
        $this->assertStringNotContainsString('XXXX', $text);
        $this->assertStringNotContainsString('SECRET_CODE', $text);
        $this->assertStringContainsString('run_tool_program failed', $text);
    }

    public function test_invalid_parameter_type_error_is_capped_and_does_not_echo_params(): void
    {
        [$program] = $this->boundProgramTool();
        $hugeCalls = str_repeat('C', 1_250_000);

        $out = $program->handle(calls: $hugeCalls, max_output_chars: 'abc');
        $text = $out instanceof ToolError ? $out->message : $out;

        $this->assertLessThanOrEqual((int) config('agent.programmatic_tool_calling.max_output_chars'), mb_strlen($text));
        $this->assertStringNotContainsString('CCCC', $text);
        $this->assertStringContainsString('rejected the call', $text);
    }

    public function test_non_finite_requested_cap_falls_back_to_the_default(): void
    {
        [$program] = $this->boundProgramTool();
        $cap = (int) config('agent.programmatic_tool_calling.max_output_chars');
        $calls = json_encode(array_fill(0, 2, ['tool' => 'echo_tool', 'arguments' => ['text' => str_repeat('w', 50_000)]]));

        foreach ([NAN, INF, -INF, 1e30] as $requested) {
            $len = mb_strlen($program->handle($calls, null, $requested));

            $this->assertLessThanOrEqual($cap, $len);
            $this->assertGreaterThan(1_000, $len, 'a non-finite cap must not collapse to the 200 floor');
        }
    }

    public function test_very_long_tool_name_is_shortened_and_result_stays_json(): void
    {
        [$program] = $this->boundProgramTool();

        $out = $program->handle(json_encode([['tool' => str_repeat('n', 10_000), 'arguments' => []]]));
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded);
        $this->assertLessThanOrEqual(101, mb_strlen($decoded['calls'][0]['tool']));
    }

    public function test_failed_post_process_with_escape_heavy_output_respects_cap(): void
    {
        // Config default 8000 (floor 500), and the lowest cap the model may request (200).
        foreach ([8_000 => null, 200 => 200] as $cap => $requested) {
            [$program] = $this->boundProgramTool();

            $this->mock(ExecuteCodeHandler::class, function ($mock) {
                $mock->shouldReceive('execute')->once()->andReturn([
                    'exit_code' => 1,
                    'stdout' => str_repeat("\x01", 100_000),
                    'stderr' => str_repeat('"', 100_000),
                    'successful' => false,
                ]);
            });

            $out = $program->handle(json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'a']]]), 'boom()', $requested);

            $this->assertLessThanOrEqual($cap, mb_strlen($out), "cap {$cap}");
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, "cap {$cap} must stay valid JSON");
            $this->assertFalse($decoded['ok']);
        }
    }

    public function test_successful_post_process_stdout_respects_cap_including_marker(): void
    {
        foreach ([8_000 => null, 200 => 200] as $cap => $requested) {
            [$program] = $this->boundProgramTool();

            $this->mock(ExecuteCodeHandler::class, function ($mock) {
                $mock->shouldReceive('execute')->once()->andReturn([
                    'exit_code' => 0, 'stdout' => str_repeat('z', 50_000), 'stderr' => '', 'successful' => true,
                ]);
            });

            $out = $program->handle(json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'a']]]), 'print(1)', $requested);

            $this->assertLessThanOrEqual($cap, mb_strlen($out), "cap {$cap}");
            $this->assertStringEndsWith('[truncated]', $out);
        }
    }

    public function test_every_result_fits_under_cap_for_mixed_batch(): void
    {
        [$program] = $this->boundProgramTool();

        foreach ([200, 500, 1_000, 8_000] as $cap) {
            $out = $program->handle(json_encode([
                ['tool' => 'echo_tool', 'arguments' => ['text' => str_repeat("q\"\n", 30_000)]],
                ['tool' => 'loud_error_tool', 'arguments' => ['text' => 'x']],
                ['tool' => 'nope', 'arguments' => []],
            ]), null, $cap);

            $this->assertLessThanOrEqual($cap, mb_strlen($out), "cap {$cap}");
        }
    }

    public function test_post_process_requires_execute_code_tool(): void
    {
        [$program] = $this->boundProgramTool(null, withExecuteCode: false);

        $this->mock(ExecuteCodeHandler::class, fn ($mock) => $mock->shouldNotReceive('execute'));

        $out = json_decode($program->handle(
            json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'a']]]),
            'print(1)',
        ), true);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('requires the execute_code tool', $out['error']);
    }

    public function test_post_process_rejection_happens_before_any_sibling_runs(): void
    {
        config(['agent.programmatic_tool_calling.enabled' => true]);
        $context = new ToolProgramContext;
        $tools = app(ToolTranslator::class)->toPrismTools($this->programTool(), [], null, null, null, $context);

        $ran = 0;
        $counter = PrismTool::as('counter')->for('c')->withStringParameter('x', 'x')
            ->using(function (string $x) use (&$ran): string {
                $ran++;

                return 'ok';
            });
        $context->bind([...$tools, $counter]);

        $tools[0]->handle(json_encode([['tool' => 'counter', 'arguments' => ['x' => '1']]]), 'print(1)');

        $this->assertSame(0, $ran);
    }

    public function test_per_execution_sub_call_budget_is_enforced_across_batches(): void
    {
        config(['agent.programmatic_tool_calling.max_calls' => 3, 'agent.programmatic_tool_calling.max_total_calls' => 5]);
        [$program, $context] = $this->boundProgramTool();

        $three = json_encode(array_fill(0, 3, ['tool' => 'echo_tool', 'arguments' => ['text' => 'z']]));

        $first = json_decode($program->handle($three), true);
        $second = json_decode($program->handle($three), true);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('budget of 5 sub-calls', $second['error']);
        $this->assertSame(3, $context->callsUsed());
    }

    public function test_post_process_receives_results_file_and_returns_stdout(): void
    {
        $workspace = new SandboxedWorkspace('exec-program-test', 'agent-x', 'team-y', sys_get_temp_dir().'/fleetq-program-test');
        [$program] = $this->boundProgramTool($workspace);

        $seen = null;
        $this->mock(ExecuteCodeHandler::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(function (string $code, int $timeout, SandboxedWorkspace $ws) use (&$seen): bool {
                    $seen = json_decode(file_get_contents($ws->resolve('tool_results.json')), true);

                    return str_contains($code, 'print(') && $timeout === 60;
                })
                ->andReturn(['exit_code' => 0, 'stdout' => "2 results\n", 'stderr' => '', 'successful' => true]);
        });

        $out = $program->handle(
            json_encode([
                ['tool' => 'echo_tool', 'arguments' => ['text' => 'a']],
                ['tool' => 'echo_tool', 'arguments' => ['text' => 'b']],
            ]),
            'import json; print(len(json.load(open("tool_results.json"))["calls"]), "results")',
        );

        $this->assertSame("2 results\n", $out);
        $this->assertNotNull($seen);
        $this->assertSame(['echo:a', 'echo:b'], array_column($seen['calls'], 'output'));
        $this->assertSame(['text' => 'a'], $seen['calls'][0]['arguments']);

        $workspace->teardown();
    }

    public function test_failed_post_process_reports_stderr_and_exit_code(): void
    {
        [$program] = $this->boundProgramTool();

        $this->mock(ExecuteCodeHandler::class, function ($mock) {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturn(['exit_code' => 1, 'stdout' => '', 'stderr' => 'NameError: x', 'successful' => false]);
        });

        $out = json_decode($program->handle(
            json_encode([['tool' => 'echo_tool', 'arguments' => ['text' => 'a']]]),
            'print(x)',
        ), true);

        $this->assertFalse($out['ok']);
        $this->assertSame(1, $out['exit_code']);
        $this->assertStringContainsString('NameError', $out['stderr']);
    }
}
