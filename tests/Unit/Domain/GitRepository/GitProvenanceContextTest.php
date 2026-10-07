<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Services\GitProvenanceContext;
use PHPUnit\Framework\TestCase;

class GitProvenanceContextTest extends TestCase
{
    public function test_with_sets_fields_and_restores_after_callback(): void
    {
        $ctx = new GitProvenanceContext;
        $ctx->experimentId = 'outer-exp';
        $ctx->source = 'mcp_tool';

        $inside = $ctx->with(
            ['experiment_id' => 'e1', 'agent_id' => 'a1', 'team_id' => 't1', 'source' => 'agent_tool'],
            fn () => $ctx->toArray(),
        );

        $this->assertSame(['team_id' => 't1', 'experiment_id' => 'e1', 'agent_id' => 'a1', 'skill_execution_id' => null, 'source' => 'agent_tool'], $inside);
        $this->assertSame('outer-exp', $ctx->experimentId);
        $this->assertNull($ctx->agentId);
        $this->assertSame('mcp_tool', $ctx->source);
    }

    public function test_with_restores_after_exception(): void
    {
        $ctx = new GitProvenanceContext;
        $ctx->agentId = 'keep';

        try {
            $ctx->with(['agent_id' => 'x', 'source' => 'warm_build'], fn () => throw new \RuntimeException('boom'));
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame('keep', $ctx->agentId);
        $this->assertSame('other', $ctx->source);
    }

    public function test_trailers_filter_nulls(): void
    {
        $ctx = new GitProvenanceContext;
        $this->assertSame([], $ctx->trailers());

        $ctx->experimentId = 'e';
        $this->assertSame(['FleetQ-Experiment' => 'e'], $ctx->trailers());

        $ctx->agentId = 'a';
        $this->assertSame(['FleetQ-Experiment' => 'e', 'FleetQ-Agent' => 'a'], $ctx->trailers());
    }
}
