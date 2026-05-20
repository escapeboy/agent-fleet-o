<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class McpCallCommandTest extends TestCase
{
    public function test_rejects_invalid_json_args(): void
    {
        $this->artisan('mcp:call', ['tool' => 'agent_list', '--args' => 'not-json'])
            ->expectsOutputToContain('--args must be a JSON object')
            ->assertExitCode(1);
    }

    public function test_reports_when_server_handle_is_unknown(): void
    {
        // No --args passed: the default must parse as an empty JSON object,
        // not trip the args validation (guards a signature-default regression).
        $this->artisan('mcp:call', [
            'tool' => 'agent_list',
            '--server' => 'no-such-server',
            '--timeout' => 20,
        ])
            ->doesntExpectOutputToContain('--args must be a JSON object')
            ->assertExitCode(1);
    }
}
