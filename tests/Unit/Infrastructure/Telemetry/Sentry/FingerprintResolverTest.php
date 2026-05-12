<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telemetry\Sentry;

use App\Infrastructure\Telemetry\Sentry\FingerprintResolver;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class FingerprintResolverTest extends TestCase
{
    #[Test]
    public function it_groups_llm_failures_by_provider_and_model(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('timeout'), [
            'sub_program' => 'llm.call',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
        ]);

        $this->assertContains('sub_program:llm.call', $fingerprint);
        $this->assertContains('provider:anthropic', $fingerprint);
        $this->assertContains('model:claude-sonnet-4-6', $fingerprint);
    }

    #[Test]
    public function it_groups_experiment_stage_failures_by_stage_and_agent(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('stage broke'), [
            'sub_program' => 'experiment.stage',
            'experiment_stage' => 'building',
            'agent_id' => 'agent-uuid-123',
        ]);

        $this->assertContains('stage:building', $fingerprint);
        $this->assertContains('agent:agent-uuid-123', $fingerprint);
    }

    #[Test]
    public function it_drops_unknown_placeholder_tokens(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('llm fail'), [
            'sub_program' => 'llm.call',
            // missing provider + model
        ]);

        foreach ($fingerprint as $token) {
            $this->assertStringNotContainsString(':unknown', $token);
        }
    }

    #[Test]
    public function it_returns_default_fallback_for_unknown_sub_program(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('mystery'), [
            'sub_program' => 'something.unmapped',
        ]);

        $this->assertSame([], $fingerprint);
    }

    #[Test]
    public function it_groups_workflow_node_failures_by_workflow_and_node(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('node fail'), [
            'sub_program' => 'workflow.node',
            'workflow_id' => 'wf-1',
            'workflow_node_id' => 'node-7',
        ]);

        $this->assertContains('workflow:wf-1', $fingerprint);
        $this->assertContains('node:node-7', $fingerprint);
    }

    #[Test]
    public function it_includes_exception_class_in_every_resolved_fingerprint(): void
    {
        $resolver = new FingerprintResolver;

        $fingerprint = $resolver->resolve(new RuntimeException('boom'), [
            'sub_program' => 'experiment.stage',
            'experiment_stage' => 'scoring',
            'agent_id' => 'a',
        ]);

        $this->assertContains(RuntimeException::class, $fingerprint);
    }
}
