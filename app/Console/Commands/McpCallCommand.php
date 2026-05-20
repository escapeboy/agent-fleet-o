<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class McpCallCommand extends Command
{
    protected $signature = 'mcp:call
        {tool : The MCP tool name to invoke (e.g. agent_list)}
        {--args= : Tool arguments as a JSON object (default: empty object)}
        {--server=agent-fleet : MCP server handle to call}
        {--timeout=60 : Seconds to wait for the server response}
        {--raw : Print the raw tools/call result instead of just the text content}';

    protected $description = 'Invoke a single MCP tool and print the JSON result (debugging)';

    public function handle(): int
    {
        $tool = (string) $this->argument('tool');
        $server = (string) $this->option('server');

        $argsRaw = trim((string) $this->option('args'));
        $arguments = json_decode($argsRaw === '' ? '{}' : $argsRaw, true);
        if (! is_array($arguments)) {
            $this->components->error('--args must be a JSON object, e.g. --args=\'{"id":"abc"}\'');

            return self::FAILURE;
        }

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $process = new Process([$php, base_path('artisan'), 'mcp:start', $server], base_path());
        $process->setTimeout((float) $this->option('timeout'));
        $process->setInput($this->buildInput($tool, $arguments));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->components->error("MCP server [{$server}] did not respond within the timeout.");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Failed to run the MCP server: '.$e->getMessage());

            return self::FAILURE;
        }

        $response = $this->extractResponse($process->getOutput());
        if ($response === null) {
            $this->components->error("No tools/call response received from server [{$server}].");
            if (($stderr = trim($process->getErrorOutput())) !== '') {
                $this->line($stderr);
            }

            return self::FAILURE;
        }

        if (isset($response['error'])) {
            $this->line($this->encode($response['error']));

            return self::FAILURE;
        }

        $result = $response['result'] ?? [];
        $this->line($this->option('raw') ? $this->encode($result) : $this->encode($this->summarize($result)));

        return ($result['isError'] ?? false) === true ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build the newline-delimited JSON-RPC handshake + tools/call request
     * that the stdio MCP server consumes from STDIN.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function buildInput(string $tool, array $arguments): string
    {
        $frames = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'mcp:call', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => $tool,
                'arguments' => empty($arguments) ? (object) [] : $arguments,
            ]],
        ];

        return implode('', array_map(fn (array $f): string => json_encode($f).PHP_EOL, $frames));
    }

    /**
     * Locate the JSON-RPC response for the tools/call request (id 2) among the
     * newline-delimited frames the server wrote to STDOUT.
     *
     * @return array<string, mixed>|null
     */
    private function extractResponse(string $output): ?array
    {
        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === 2) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Flatten a tools/call result to its text content for readable output.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|string
     */
    private function summarize(array $result): array|string
    {
        $text = collect($result['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return $text !== '' ? $text : $result;
    }

    private function encode(mixed $value): string
    {
        if (is_string($value)) {
            $json = json_decode($value, true);

            return is_array($json) ? (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
