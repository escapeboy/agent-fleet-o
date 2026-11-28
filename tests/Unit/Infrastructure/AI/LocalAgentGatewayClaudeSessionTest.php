<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\ClaudeSessionContext;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Direct host execution of Claude Code for crew-task runs persists the claude
 * session and resumes it on a retry when the (system prompt, workdir, model)
 * hash is unchanged. Uses a fake `claude` binary that logs its argv and stdin.
 */
class LocalAgentGatewayClaudeSessionTest extends TestCase
{
    private const AGENT_ID = '0195f2a4-0000-7000-8000-000000000001';

    private string $dir;

    private string $argsFile;

    private string $stdinFile;

    private LocalAgentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/claude-session-test-'.uniqid();
        mkdir($this->dir);
        $this->argsFile = $this->dir.'/args.log';
        $this->stdinFile = $this->dir.'/stdin.log';
        $binary = $this->dir.'/claude';

        file_put_contents($binary, implode("\n", [
            '#!/bin/sh',
            'printf "%s\n" "$*" >> '.escapeshellarg($this->argsFile),
            'cat >> '.escapeshellarg($this->stdinFile),
            'printf "\n---END---\n" >> '.escapeshellarg($this->stdinFile),
            'case "$*" in *--resume*) if [ -f '.escapeshellarg($this->dir.'/fail-resume').' ]; then echo "No conversation found" >&2; exit 2; fi;; esac',
            'echo \'{"type":"result","result":"done","session_id":"sess-new"}\'',
            '',
        ]));
        chmod($binary, 0755);

        config(['local_agents.agents' => [
            'claude-code' => [
                'name' => 'Claude Code',
                'binary' => 'claude',
                'detect_command' => 'claude --version',
                'requires_env' => '',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);
        config(['local_agents.timeout' => 20]);
        config(['local_agents.working_directory' => null]);

        /** @var LocalAgentDiscovery&MockInterface $discovery */
        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('agentConfig')->andReturnUsing(fn ($k) => config("local_agents.agents.{$k}"));
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        $discovery->shouldReceive('binaryPath')->with('claude-code')->andReturn($binary);

        $this->gateway = new LocalAgentGateway($discovery);
    }

    protected function tearDown(): void
    {
        foreach (['claude', 'args.log', 'stdin.log', 'fail-resume'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_without_session_context_keeps_one_shot_run(): void
    {
        $response = $this->gateway->complete($this->request());

        $this->assertSame('done', $response->content);
        $args = $this->invocations();
        $this->assertCount(1, $args);
        $this->assertStringContainsString('--no-session-persistence', $args[0]);
        $this->assertStringNotContainsString('--resume', $args[0]);
    }

    public function test_other_purpose_keeps_one_shot_run_even_with_context(): void
    {
        $this->inContext(null, null, fn () => $this->gateway->complete($this->request('agent.workflow_step.schema_retry')));

        $this->assertStringContainsString('--no-session-persistence', $this->invocations()[0]);
        $this->assertNull(app(ClaudeSessionContext::class)->resultSessionId);
    }

    public function test_first_attempt_persists_session_and_records_it(): void
    {
        $recorded = $this->inContext(null, null, fn () => $this->gateway->complete($this->request()));

        $args = $this->invocations();
        $this->assertCount(1, $args);
        $this->assertStringNotContainsString('--no-session-persistence', $args[0]);
        $this->assertStringNotContainsString('--resume', $args[0]);
        $this->assertStringContainsString('You are the writer.', $this->stdin()[0]);

        $this->assertSame('sess-new', $recorded['session_id']);
        $this->assertSame($this->expectedHash(), $recorded['hash']);
    }

    public function test_retry_with_matching_hash_resumes_and_sends_only_user_prompt(): void
    {
        $recorded = $this->inContext('sess-old', $this->expectedHash(), fn () => $this->gateway->complete($this->request()));

        $args = $this->invocations();
        $this->assertCount(1, $args);
        $this->assertStringContainsString('--resume sess-old', $args[0]);
        $this->assertStringNotContainsString('--no-session-persistence', $args[0]);

        $stdin = $this->stdin()[0];
        $this->assertStringContainsString('Write the report.', $stdin);
        $this->assertStringNotContainsString('You are the writer.', $stdin);

        $this->assertSame('sess-new', $recorded['session_id']);
    }

    public function test_changed_hash_starts_fresh_session(): void
    {
        $staleHash = ClaudeSessionContext::hash('Old instructions', base_path(), 'claude-code');

        $recorded = $this->inContext('sess-old', $staleHash, fn () => $this->gateway->complete($this->request()));

        $args = $this->invocations();
        $this->assertCount(1, $args);
        $this->assertStringNotContainsString('--resume', $args[0]);
        $this->assertStringNotContainsString('--no-session-persistence', $args[0]);
        $this->assertStringContainsString('You are the writer.', $this->stdin()[0]);
        $this->assertSame($this->expectedHash(), $recorded['hash']);
    }

    public function test_failed_resume_falls_back_to_fresh_session(): void
    {
        touch($this->dir.'/fail-resume');

        $recorded = $this->inContext('sess-gone', $this->expectedHash(), fn () => $this->gateway->complete($this->request()));

        $args = $this->invocations();
        $this->assertCount(2, $args);
        $this->assertStringContainsString('--resume sess-gone', $args[0]);
        $this->assertStringNotContainsString('--resume', $args[1]);
        $this->assertStringNotContainsString('--no-session-persistence', $args[1]);
        $this->assertStringContainsString('You are the writer.', $this->stdin()[1]);

        $this->assertSame('done', $recorded['content']);
        $this->assertSame('sess-new', $recorded['session_id']);
    }

    public function test_hash_depends_on_prompt_workdir_and_model(): void
    {
        $base = ClaudeSessionContext::hash('p', '/w', 'm');

        $this->assertSame($base, ClaudeSessionContext::hash('p', '/w', 'm'));
        $this->assertNotSame($base, ClaudeSessionContext::hash('p2', '/w', 'm'));
        $this->assertNotSame($base, ClaudeSessionContext::hash('p', '/w2', 'm'));
        $this->assertNotSame($base, ClaudeSessionContext::hash('p', '/w', 'm2'));
        $this->assertNotSame($base, ClaudeSessionContext::hash('p', '/w', null));
        $this->assertSame(64, strlen($base));
    }

    /**
     * @param  callable(): AiResponseDTO  $fn
     * @return array{content: string, session_id: string|null, hash: string|null}
     */
    private function inContext(?string $storedSessionId, ?string $storedHash, callable $fn): array
    {
        $context = app(ClaudeSessionContext::class);

        return $context->with(self::AGENT_ID, $storedSessionId, $storedHash, function () use ($fn, $context) {
            $response = $fn();

            return [
                'content' => $response->content,
                'session_id' => $context->resultSessionId,
                'hash' => $context->resultHash,
            ];
        });
    }

    private function request(string $purpose = 'agent.workflow_step'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'local',
            model: 'claude-code',
            systemPrompt: 'You are the writer.',
            userPrompt: 'Write the report.',
            agentId: self::AGENT_ID,
            purpose: $purpose,
        );
    }

    private function expectedHash(): string
    {
        return ClaudeSessionContext::hash('You are the writer.', base_path(), 'claude-code');
    }

    /**
     * @return array<int, string>
     */
    private function invocations(): array
    {
        $content = is_file($this->argsFile) ? (string) file_get_contents($this->argsFile) : '';

        return array_values(array_filter(explode("\n", $content), fn ($l) => $l !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function stdin(): array
    {
        $content = is_file($this->stdinFile) ? (string) file_get_contents($this->stdinFile) : '';

        return array_values(array_filter(explode("\n---END---\n", $content), fn ($l) => $l !== ''));
    }
}
