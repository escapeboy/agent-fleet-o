<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use RuntimeException;
use Tests\TestCase;

/**
 * discover($tool, $budget) is the per-run refresh path: the budget must be a
 * wall-clock bound for the whole call, and the server's process group must be
 * gone when it returns. Children here are real processes, not fakes.
 */
class McpStdioClientBudgetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('posix_kill') || ! is_executable('/usr/bin/setsid') && ! is_executable('/bin/setsid')) {
            $this->markTestSkipped('needs ext-posix and setsid(1) to kill the process group');
        }

        config(['agent.mcp_stdio_allow_any_binary' => true, 'agent.mcp_stdio_binary_allowlist' => []]);
        $this->dir = sys_get_temp_dir().'/mcp-stdio-budget-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_child_that_ignores_sigterm_is_killed_near_the_budget(): void
    {
        $pidFile = $this->dir.'/grandchild.pid';
        $script = $this->fixture('ignore_term.sh', "trap \"\" TERM\nsleep 20 &\necho \$! > \"\$1\"\nwait\n");

        $elapsed = $this->discoverExpectingFailure('/bin/sh', [$script, $pidFile], 2);

        $this->assertLessThan(3.5, $elapsed, 'SIGTERM-ignoring child must not hold the call past budget + grace');
        $this->assertProcessGone($this->readPid($pidFile));
    }

    public function test_slow_handshake_does_not_get_extra_time_for_listing(): void
    {
        $server = $this->fixture('slow_init.php', <<<'PHP'
            <?php
            fgets(STDIN);
            usleep(1900000);
            echo json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass, 'serverInfo' => ['name' => 'x', 'version' => '1']]])."\n";
            fflush(STDOUT);
            sleep(20);
            PHP);

        $elapsed = $this->discoverExpectingFailure(PHP_BINARY, [$server], 2);

        $this->assertLessThan(2.5, $elapsed, 'handshake and listing share one deadline');
    }

    public function test_grandchild_does_not_survive_the_call(): void
    {
        $pidFile = $this->dir.'/grandchild.pid';
        $script = $this->fixture('spawn.sh', "sleep 20 &\necho \$! > \"\$1\"\nwait\n");

        $this->discoverExpectingFailure('/bin/sh', [$script, $pidFile], 1);

        $this->assertProcessGone($this->readPid($pidFile));
    }

    public function test_grandchild_of_a_server_that_already_exited_does_not_survive(): void
    {
        $pidFile = $this->dir.'/grandchild.pid';
        $script = $this->fixture('orphan.sh', "sleep 21 &\necho \$! > \"\$1\"\nexit 0\n");

        $this->discoverExpectingFailure('/bin/sh', [$script, $pidFile], 1);

        $this->assertProcessGone($this->readPid($pidFile));
    }

    public function test_responsive_server_still_lists_under_a_budget(): void
    {
        $server = $this->fixture('server.php', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                $result = match ($msg['method'] ?? null) {
                    'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass, 'serverInfo' => ['name' => 'x', 'version' => '1']],
                    'tools/list' => ['tools' => [['name' => 'ping', 'inputSchema' => ['type' => 'object']]]],
                    default => null,
                };
                if ($result !== null) {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => $result])."\n";
                    fflush(STDOUT);
                }
            }
            PHP);

        $tools = app(McpStdioClient::class)->discover($this->tool(PHP_BINARY, [$server]), 5);

        $this->assertSame(['ping'], array_column($tools, 'name'));
    }

    /**
     * @param  list<string>  $args
     */
    private function discoverExpectingFailure(string $command, array $args, int $budget): float
    {
        $started = microtime(true);

        try {
            app(McpStdioClient::class)->discover($this->tool($command, $args), $budget);
            $this->fail('discover() should time out');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('McpStdioClient', $e->getMessage());
        }

        return microtime(true) - $started;
    }

    /**
     * @param  list<string>  $args
     */
    private function tool(string $command, array $args): Tool
    {
        $tool = new Tool;
        $tool->name = 'budget-probe';
        $tool->transport_config = ['command' => $command, 'args' => $args];

        return $tool;
    }

    private function fixture(string $name, string $body): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $body);

        return $path;
    }

    private function readPid(string $pidFile): int
    {
        $pid = (int) trim((string) @file_get_contents($pidFile));
        $this->assertGreaterThan(0, $pid, 'fixture did not record its grandchild pid');

        return $pid;
    }

    /**
     * A killed grandchild is reparented and may sit as a zombie until init
     * reaps it; a zombie runs nothing, so it counts as gone.
     */
    private function assertProcessGone(int $pid): void
    {
        $alive = true;
        $until = microtime(true) + 0.2;
        do {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            $alive = is_string($stat)
                ? ! str_contains(substr($stat, strrpos($stat, ')') + 1, 3), 'Z')
                : posix_kill($pid, 0);
            if ($alive) {
                usleep(10_000);
            }
        } while ($alive && microtime(true) < $until);

        if ($alive) {
            posix_kill($pid, SIGKILL);
        }

        $this->assertFalse($alive, "process {$pid} survived the call");
    }
}
