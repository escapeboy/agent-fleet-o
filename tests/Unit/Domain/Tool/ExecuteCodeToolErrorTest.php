<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\BuiltIn\ExecuteCodeHandler;
use App\Domain\Tool\Services\ToolTranslator;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Prism\Prism\Tool as PrismToolObject;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * execute_code must not return the model's own code (embedded in Laravel's
 * process-timeout message) or an uncapped exception message.
 */
class ExecuteCodeToolErrorTest extends TestCase
{
    use RefreshDatabase;

    private function executeCodeTool(): PrismToolObject
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'execute_code'],
            'settings' => ['timeout' => 30],
        ]);

        return app(ToolTranslator::class)->toPrismTools($tool)[0];
    }

    /**
     * @param  Closure(string, int): array<string, mixed>  $fn
     */
    private function fakeHandler(Closure $fn): void
    {
        app()->instance(ExecuteCodeHandler::class, new class($fn) extends ExecuteCodeHandler
        {
            public function __construct(private readonly Closure $fn) {}

            public function execute(string $code, int $timeoutSeconds = 30, ?SandboxedWorkspace $workspace = null): array
            {
                return ($this->fn)($code, $timeoutSeconds);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $result): array
    {
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_timeout_reports_duration_and_partial_output_without_the_command_line(): void
    {
        $this->fakeHandler(function (string $code): array {
            $process = new Process(['sh', '-c', 'echo partial-output', $code]);
            $process->run();

            throw new ProcessTimedOutException(
                new SymfonyTimeoutException($process, SymfonyTimeoutException::TYPE_GENERAL),
                new ProcessResult($process),
            );
        });

        $raw = $this->executeCodeTool()->handle(code: 'print("SECRET-CODE-MARKER")', timeout_seconds: 5.0);
        $out = $this->decode($raw);

        $this->assertFalse($out['successful']);
        $this->assertNull($out['exit_code']);
        $this->assertSame('Execution timed out after 5 seconds.', $out['stderr']);
        $this->assertStringContainsString('partial-output', $out['stdout']);
        $this->assertStringNotContainsString('SECRET-CODE-MARKER', $raw);
    }

    public function test_other_exceptions_return_only_the_class_name(): void
    {
        $this->fakeHandler(function (string $code): array {
            throw new RuntimeException('docker failed running '.$code.str_repeat('!', 50_000));
        });

        $raw = $this->executeCodeTool()->handle(code: 'SECRET-CODE-MARKER');
        $out = $this->decode($raw);

        $this->assertSame('execute_code failed: RuntimeException.', $out['stderr']);
        $this->assertStringNotContainsString('SECRET-CODE-MARKER', $raw);
        $this->assertLessThan(500, strlen($raw));
    }

    public function test_invalid_utf8_output_still_returns_valid_json(): void
    {
        $this->fakeHandler(fn (): array => [
            'stdout' => "ok\xFF\xFE",
            'stderr' => '',
            'exit_code' => 0,
            'successful' => true,
        ]);

        $out = $this->decode($this->executeCodeTool()->handle(code: 'x'));

        $this->assertTrue($out['successful']);
        $this->assertStringStartsWith('ok', $out['stdout']);
    }

    public function test_timeout_argument_is_clamped_to_a_positive_bounded_value(): void
    {
        $seen = [];
        $this->fakeHandler(function (string $code, int $timeout) use (&$seen): array {
            $seen[] = $timeout;

            return ['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'successful' => true];
        });

        $tool = $this->executeCodeTool();
        $tool->handle(code: 'x', timeout_seconds: 0.0);
        $tool->handle(code: 'x', timeout_seconds: -5.0);
        $tool->handle(code: 'x', timeout_seconds: 1e30);
        $tool->handle(code: 'x', timeout_seconds: NAN);
        $tool->handle(code: 'x');

        $this->assertSame([1, 1, 120, 30, 30], $seen);
    }
}
