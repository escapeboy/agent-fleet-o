<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\AgentToolAllowlistContext;
use RuntimeException;
use Tests\TestCase;

class AgentToolAllowlistContextTest extends TestCase
{
    public function test_with_sets_allowlist_for_agent_and_restores_afterwards(): void
    {
        $context = new AgentToolAllowlistContext;

        $seen = $context->with('agent-1', ['tool-a'], function () use ($context) {
            return [
                'own' => $context->allowedToolIdsFor('agent-1'),
                'other' => $context->allowedToolIdsFor('agent-2'),
                'none' => $context->allowedToolIdsFor(null),
            ];
        });

        $this->assertSame(['tool-a'], $seen['own']);
        $this->assertNull($seen['other']);
        $this->assertNull($seen['none']);
        $this->assertNull($context->allowedToolIdsFor('agent-1'));
    }

    public function test_returns_null_when_nothing_is_set(): void
    {
        $context = new AgentToolAllowlistContext;

        $this->assertNull($context->allowedToolIdsFor('agent-1'));
    }

    public function test_restores_previous_value_when_callback_throws(): void
    {
        $context = new AgentToolAllowlistContext;

        $context->with('outer', ['tool-o'], function () use ($context) {
            try {
                $context->with('inner', ['tool-i'], function () {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame(['tool-o'], $context->allowedToolIdsFor('outer'));
            $this->assertNull($context->allowedToolIdsFor('inner'));
        });

        $this->assertNull($context->allowedToolIdsFor('outer'));
    }

    public function test_empty_array_is_normalised_to_null(): void
    {
        $context = new AgentToolAllowlistContext;

        $seen = $context->with('agent-1', [], fn () => $context->allowedToolIdsFor('agent-1'));

        $this->assertNull($seen);
    }

    public function test_with_returns_callback_result(): void
    {
        $context = new AgentToolAllowlistContext;

        $this->assertSame('ok', $context->with('agent-1', ['tool-a'], fn () => 'ok'));
    }

    public function test_context_is_bound_as_scoped_instance(): void
    {
        $this->assertSame(
            app(AgentToolAllowlistContext::class),
            app(AgentToolAllowlistContext::class),
        );
    }
}
