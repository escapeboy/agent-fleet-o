<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Services\ToolErrorGuard;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolError;
use RuntimeException;
use Tests\TestCase;

/**
 * Prism's default tool error handler echoes the raw exception message and every
 * received argument, uncapped. ToolErrorGuard replaces it on tools that have no
 * handler of their own.
 */
class ToolErrorGuardTest extends TestCase
{
    private function throwingTool(string $message): PrismToolObject
    {
        return PrismTool::as('boom')
            ->for('throws')
            ->withStringParameter('query', 'q')
            ->using(function (string $query) use ($message): string {
                throw new RuntimeException($message);
            });
    }

    public function test_runtime_error_is_capped_and_names_the_exception_class(): void
    {
        [$guarded] = ToolErrorGuard::apply([$this->throwingTool(str_repeat('x', 50_000))]);

        $result = $guarded->handle(query: 'SECRET-ARG-VALUE');

        $this->assertInstanceOf(ToolError::class, $result);
        $this->assertStringContainsString('Tool boom failed (RuntimeException)', $result->message);
        $this->assertStringContainsString('…[truncated]', $result->message);
        $this->assertLessThanOrEqual(ToolErrorGuard::MAX_MESSAGE_CHARS + 60, mb_strlen($result->message));
        $this->assertStringNotContainsString('SECRET-ARG-VALUE', $result->message);
    }

    public function test_type_error_does_not_echo_argument_values(): void
    {
        $tool = PrismTool::as('typed')
            ->for('typed')
            ->withStringParameter('code', 'code')
            ->using(fn (string $code): string => 'ok');

        [$guarded] = ToolErrorGuard::apply([$tool]);
        $result = $guarded->handle(code: ['SECRET-ARRAY-VALUE' => str_repeat('y', 10_000)]);

        $this->assertInstanceOf(ToolError::class, $result);
        $this->assertStringStartsWith('Parameter validation error in tool typed:', $result->message);
        $this->assertStringContainsString('Expected parameters: [code]', $result->message);
        $this->assertStringNotContainsString('SECRET-ARRAY-VALUE', $result->message);
        $this->assertStringNotContainsString('yyyy', $result->message);
    }

    public function test_unknown_named_parameter_is_a_parameter_error_without_values(): void
    {
        [$guarded] = ToolErrorGuard::apply([$this->throwingTool('unused')]);

        $result = $guarded->handle(nope: 'SECRET-UNKNOWN-VALUE');

        $this->assertInstanceOf(ToolError::class, $result);
        $this->assertStringStartsWith('Parameter validation error in tool boom:', $result->message);
        $this->assertStringNotContainsString('SECRET-UNKNOWN-VALUE', $result->message);
    }

    public function test_tool_with_its_own_handler_is_returned_unchanged(): void
    {
        $tool = $this->throwingTool('inner')->failed(fn (\Throwable $e): string => 'custom handler');

        [$out] = ToolErrorGuard::apply([$tool]);

        $this->assertSame($tool, $out);
        $this->assertSame('custom handler', $out->handle(query: 'x')->message);
    }

    public function test_tool_with_error_handling_disabled_is_returned_unchanged(): void
    {
        $tool = $this->throwingTool('inner')->withoutErrorHandling();

        [$out] = ToolErrorGuard::apply([$tool]);

        $this->assertSame($tool, $out);
        $this->assertFalse($out->failedHandler());
    }

    public function test_input_tools_are_not_mutated_and_keys_are_preserved(): void
    {
        $tool = $this->throwingTool('inner');

        $out = ToolErrorGuard::apply(['a' => $tool, 'b' => 'not a tool']);

        $this->assertNull($tool->failedHandler());
        $this->assertNotSame($tool, $out['a']);
        $this->assertNotNull($out['a']->failedHandler());
        $this->assertSame('not a tool', $out['b']);
    }

    public function test_cap_message_returns_valid_utf8_without_control_characters(): void
    {
        $capped = ToolErrorGuard::capMessage("line1\nbad\xFF\x00byte\x1B[31m", 1000);

        $this->assertTrue(mb_check_encoding($capped, 'UTF-8'));
        $this->assertStringNotContainsString("\x00", $capped);
        $this->assertStringNotContainsString("\x1B", $capped);
        $this->assertStringContainsString("line1\n", $capped);
    }

    public function test_cap_message_respects_the_limit_including_marker(): void
    {
        $this->assertSame(200, mb_strlen(ToolErrorGuard::capMessage(str_repeat('é', 5000), 200)));
    }
}
