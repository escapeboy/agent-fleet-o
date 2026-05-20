<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Request-scoped Phoenix trace context.
 *
 * Callers (ExecuteAgentAction, PlaybookExecutor, ExecuteCrewJob) wrap a unit
 * of work in `withRoot()`. While inside the closure, `currentTraceId()` /
 * `currentSpanId()` return the active wrapper's IDs — `AiRequestDTO` factories
 * read them and stamp the request so nested LLM spans land under the root.
 *
 * Sampling is decided once at `withRoot()` entry: if the rate roll fails the
 * root span is not emitted and child calls run without a parent (they'll be
 * stand-alone spans). When sampled in, the root span is emitted on closure
 * exit; children that read the IDs are guaranteed-emit regardless of their
 * own sampling roll (`forceSample` semantics in the middleware).
 *
 * No persistence across queue jobs — when a job runs, it can re-enter
 * `withRoot()` if needed. Cross-process propagation is out of scope for v1.
 */
class PhoenixTraceContext
{
    /**
     * Stack of active roots: [['trace' => hex32, 'span' => hex16, 'emit' => bool], ...].
     *
     * @var list<array{trace: string, span: string, name: string, start_nanos: int, attributes: array<string, scalar|null>, emit: bool}>
     */
    private array $stack = [];

    /**
     * Run $work wrapped in a root span. Returns whatever $work returns.
     *
     * Sampling: rolls `llmops.phoenix.sample_rate` ONCE at the outermost root
     * entry. Re-entering `withRoot()` while a sampled-in root is active
     * inherits the same traceId and always emits.
     *
     * @template T
     *
     * @param  array<string, scalar|null>  $attributes
     * @param  Closure(): T  $work
     * @return T
     */
    public function withRoot(string $name, array $attributes, Closure $work): mixed
    {
        $emit = $this->shouldEmit();
        $traceId = $this->currentTraceId() ?? $this->randomHex(32);
        $spanId = $this->randomHex(16);
        $startNanos = (int) (microtime(true) * 1_000_000_000);

        $this->stack[] = [
            'trace' => $traceId,
            'span' => $spanId,
            'name' => $name,
            'start_nanos' => $startNanos,
            'attributes' => array_merge(
                ['openinference.span.kind' => $this->resolveSpanKind($name)],
                $attributes,
            ),
            'emit' => $emit,
        ];

        try {
            return $work();
        } finally {
            $frame = array_pop($this->stack);

            if ($frame !== null && $frame['emit']) {
                $this->dispatchRootSpan($frame);
            }
        }
    }

    /**
     * Manual variant of `withRoot()` for callers whose body has too many
     * early returns / scattered control flow to cleanly close over.
     *
     * Always pair `push()` with `pop()` in a try/finally — leaving the stack
     * dirty leaks parent context across requests.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function push(string $name, array $attributes): void
    {
        $emit = $this->shouldEmit();
        $traceId = $this->currentTraceId() ?? $this->randomHex(32);
        $spanId = $this->randomHex(16);
        $startNanos = (int) (microtime(true) * 1_000_000_000);

        $this->stack[] = [
            'trace' => $traceId,
            'span' => $spanId,
            'name' => $name,
            'start_nanos' => $startNanos,
            'attributes' => array_merge(
                ['openinference.span.kind' => $this->resolveSpanKind($name)],
                $attributes,
            ),
            'emit' => $emit,
        ];
    }

    public function pop(): void
    {
        $frame = array_pop($this->stack);
        if ($frame !== null && $frame['emit']) {
            $this->dispatchRootSpan($frame);
        }
    }

    public function currentTraceId(): ?string
    {
        $top = end($this->stack);

        return $top === false ? null : $top['trace'];
    }

    public function currentSpanId(): ?string
    {
        $top = end($this->stack);

        return $top === false ? null : $top['span'];
    }

    public function isActive(): bool
    {
        return $this->stack !== [];
    }

    /**
     * Test helper — wipe state. Production code never calls this.
     */
    public function reset(): void
    {
        $this->stack = [];
    }

    private function shouldEmit(): bool
    {
        // Once a root in the stack is sampled-in, children inherit (always emit).
        if ($this->stack !== []) {
            return end($this->stack)['emit'];
        }

        if (! (bool) config('llmops.phoenix.enabled', false)) {
            return false;
        }

        $rate = (float) config('llmops.phoenix.sample_rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    /**
     * @param  array{trace: string, span: string, name: string, start_nanos: int, attributes: array<string, scalar|null>, emit: bool}  $frame
     */
    private function dispatchRootSpan(array $frame): void
    {
        try {
            $endNanos = (int) (microtime(true) * 1_000_000_000);

            ExportToPhoenixJob::dispatch(
                endpoint: (string) config('llmops.phoenix.endpoint', ''),
                spanName: $frame['name'],
                attributes: $frame['attributes'],
                startNanos: $frame['start_nanos'],
                endNanos: $endNanos,
                apiKey: (string) config('llmops.phoenix.api_key', ''),
                project: (string) config('llmops.phoenix.project', 'fleetq'),
                traceId: $frame['trace'],
                spanId: $frame['span'],
            );
        } catch (\Throwable $e) {
            Log::warning('PhoenixTraceContext: failed to dispatch root span, swallowing', [
                'error' => $e->getMessage(),
                'span' => $frame['name'],
            ]);
        }
    }

    private function resolveSpanKind(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'agent.') => 'AGENT',
            str_starts_with($name, 'crew.') => 'CHAIN',
            str_starts_with($name, 'playbook.') => 'CHAIN',
            str_starts_with($name, 'tool.') => 'TOOL',
            default => 'CHAIN',
        };
    }

    private function randomHex(int $length): string
    {
        $bytes = (int) ceil($length / 2);

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}
