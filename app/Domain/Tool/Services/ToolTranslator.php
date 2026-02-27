<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class ToolTranslator
{
    public function __construct(
        private readonly ?SshExecutor $sshExecutor = null,
    ) {}

    /**
     * Convert a Tool model into PrismPHP Tool objects.
     *
     * @return array<PrismToolObject>
     */
    public function toPrismTools(Tool $tool, array $overrides = [], ?array $orgPolicy = null): array
    {
        if ($tool->isBuiltIn()) {
            return $this->translateBuiltInTool($tool, $overrides, $orgPolicy);
        }

        if ($tool->isMcp()) {
            return $this->translateMcpTool($tool, $overrides);
        }

        return [];
    }

    /**
     * Build PrismPHP Tools from stored tool_definitions (for MCP tools).
     * Each definition has: name, description, input_schema.
     */
    private function translateMcpTool(Tool $tool, array $overrides): array
    {
        $definitions = $tool->tool_definitions ?? [];
        $enabledFunctions = $overrides['enabled_functions'] ?? null;
        $disabledFunctions = $overrides['disabled_functions'] ?? [];
        $tools = [];

        foreach ($definitions as $definition) {
            $name = $definition['name'] ?? '';
            if (! $name) {
                continue;
            }

            // Apply function filtering
            if ($enabledFunctions !== null && ! in_array($name, $enabledFunctions)) {
                continue;
            }
            if (in_array($name, $disabledFunctions)) {
                continue;
            }

            $prismTool = PrismTool::as($name)
                ->for($definition['description'] ?? '');

            // Add parameters from input_schema
            $schema = $definition['input_schema'] ?? [];
            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];

            foreach ($properties as $paramName => $paramDef) {
                $paramDesc = $paramDef['description'] ?? '';
                $isRequired = in_array($paramName, $required);

                $prismTool = match ($paramDef['type'] ?? 'string') {
                    'string' => $prismTool->withStringParameter($paramName, $paramDesc, $isRequired),
                    'number', 'integer' => $prismTool->withNumberParameter($paramName, $paramDesc, $isRequired),
                    'boolean' => $prismTool->withBooleanParameter($paramName, $paramDesc, $isRequired),
                    default => $prismTool->withStringParameter($paramName, $paramDesc, $isRequired),
                };
            }

            // MCP tool handler: calls the MCP server when invoked
            $toolModel = $tool;
            $prismTool->using(function () use ($toolModel, $name) {
                // Placeholder: MCP call will be handled by Relay when installed.
                // For now, return a message indicating the tool was called.
                return "Tool '{$name}' on server '{$toolModel->name}' was called. "
                    .'MCP server execution requires prism-php/relay package.';
            });

            $tools[] = $prismTool;
        }

        return $tools;
    }

    /**
     * Build PrismPHP Tools for built-in host capabilities.
     */
    private function translateBuiltInTool(Tool $tool, array $overrides, ?array $orgPolicy = null): array
    {
        $kind = BuiltInToolKind::tryFrom($tool->transport_config['kind'] ?? 'bash');

        return match ($kind) {
            BuiltInToolKind::Bash => $this->buildBashTools($tool, $overrides, $orgPolicy),
            BuiltInToolKind::Filesystem => $this->buildFilesystemTools($tool, $overrides),
            BuiltInToolKind::Browser => [],
            BuiltInToolKind::Ssh => $this->buildSshTools($tool, $overrides),
            default => [],
        };
    }

    private function buildBashTools(Tool $tool, array $overrides, ?array $orgPolicy = null): array
    {
        $config = $tool->transport_config;
        $allowedCommands = $config['allowed_commands'] ?? [
            'curl', 'jq', 'python3', 'node', 'grep', 'awk', 'sed',
            'cat', 'echo', 'ls', 'find', 'wc', 'head', 'tail', 'sort', 'uniq',
        ];
        $allowedPaths = $config['allowed_paths'] ?? ['/tmp/agent-workspace'];
        $timeout = $tool->settings['timeout'] ?? 30;
        $maxOutputChars = 10000;

        $pathDescription = implode(', ', $allowedPaths);
        $commandDescription = implode(', ', $allowedCommands);

        return [
            PrismTool::as('bash_execute')
                ->for("Execute a shell command. Allowed commands: {$commandDescription}. Working directory restricted to: {$pathDescription}")
                ->withStringParameter('command', 'The shell command to execute')
                ->withStringParameter('working_directory', 'Working directory (must be within allowed paths)', required: false)
                ->using(function (string $command, ?string $working_directory = null) use ($allowedCommands, $allowedPaths, $timeout, $maxOutputChars, $orgPolicy): string {
                    return $this->executeBashCommand($command, $working_directory, $allowedCommands, $allowedPaths, $timeout, $maxOutputChars, orgSecurityPolicy: $orgPolicy);
                }),
        ];
    }

    private function executeBashCommand(
        string $command,
        ?string $workingDirectory,
        array $allowedCommands,
        array $allowedPaths,
        int $timeout,
        int $maxOutputChars,
        ?array $projectCommandPolicy = null,
        ?array $agentCommandPolicy = null,
        ?array $orgSecurityPolicy = null,
    ): string {
        $cwd = $workingDirectory ?? $allowedPaths[0] ?? '/tmp';

        // Delegate security validation to CommandSecurityPolicy
        $policy = app(CommandSecurityPolicy::class);
        $validation = $policy->validate(
            $command, $cwd, $allowedCommands, $allowedPaths,
            $projectCommandPolicy, $agentCommandPolicy, $orgSecurityPolicy,
        );

        if (! $validation->allowed) {
            return "Error: {$validation->reason}";
        }

        if ($validation->requiresApproval) {
            activity('tool_security')
                ->withProperties([
                    'command' => $command,
                    'level' => $validation->level,
                    'working_directory' => $cwd,
                ])
                ->log("Command requires approval: {$command}");
        }

        // Ensure the working directory exists
        if (! is_dir($cwd)) {
            @mkdir($cwd, 0755, true);
        }

        $result = Process::timeout($timeout)->path($cwd)->run($command);

        if ($result->successful()) {
            $output = $result->output();

            return mb_strlen($output) > $maxOutputChars
                ? mb_substr($output, 0, $maxOutputChars)."\n... [output truncated at {$maxOutputChars} chars]"
                : $output;
        }

        $errorOutput = mb_substr($result->errorOutput(), 0, 2000);

        return "Command failed (exit {$result->exitCode()}): {$errorOutput}";
    }

    private function buildFilesystemTools(Tool $tool, array $overrides): array
    {
        $config = $tool->transport_config;
        $allowedPaths = $config['allowed_paths'] ?? ['/tmp/agent-workspace'];
        $readOnly = $config['read_only'] ?? false;
        $maxReadSize = 50000;
        $pathDescription = implode(', ', $allowedPaths);

        $tools = [];

        // Read file
        $tools[] = PrismTool::as('file_read')
            ->for("Read a file's contents. Paths restricted to: {$pathDescription}")
            ->withStringParameter('path', 'Absolute path to the file to read')
            ->using(function (string $path) use ($allowedPaths, $maxReadSize): string {
                if (! $this->isPathAllowed($path, $allowedPaths)) {
                    return "Error: Path '{$path}' is outside allowed directories.";
                }
                if (! file_exists($path)) {
                    return "Error: File not found: {$path}";
                }
                $content = file_get_contents($path);
                if ($content === false) {
                    return "Error: Could not read file: {$path}";
                }

                return mb_strlen($content) > $maxReadSize
                    ? mb_substr($content, 0, $maxReadSize)."\n... [truncated at {$maxReadSize} chars]"
                    : $content;
            });

        // List directory
        $tools[] = PrismTool::as('file_list')
            ->for("List files in a directory. Paths restricted to: {$pathDescription}")
            ->withStringParameter('path', 'Absolute path to the directory to list')
            ->using(function (string $path) use ($allowedPaths): string {
                if (! $this->isPathAllowed($path, $allowedPaths)) {
                    return "Error: Path '{$path}' is outside allowed directories.";
                }
                if (! is_dir($path)) {
                    return "Error: Not a directory: {$path}";
                }
                $entries = scandir($path);

                return implode("\n", array_diff($entries ?: [], ['.', '..']));
            });

        // Write file (if not read-only)
        if (! $readOnly) {
            $tools[] = PrismTool::as('file_write')
                ->for("Write content to a file. Paths restricted to: {$pathDescription}")
                ->withStringParameter('path', 'Absolute path to the file to write')
                ->withStringParameter('content', 'The content to write')
                ->using(function (string $path, string $content) use ($allowedPaths): string {
                    if (! $this->isPathAllowed($path, $allowedPaths)) {
                        return "Error: Path '{$path}' is outside allowed directories.";
                    }
                    $dir = dirname($path);
                    if (! is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $bytes = file_put_contents($path, $content);

                    return $bytes !== false
                        ? "Written {$bytes} bytes to {$path}"
                        : "Error: Could not write to {$path}";
                });
        }

        return $tools;
    }

    private function buildSshTools(Tool $tool, array $overrides): array
    {
        $config = $tool->transport_config;
        $host = $config['host'] ?? null;
        $port = (int) ($config['port'] ?? 22);
        $username = $config['username'] ?? 'root';
        $credentialId = $config['credential_id'] ?? null;
        $allowedCommands = $config['allowed_commands'] ?? [];
        $timeout = (int) ($tool->settings['timeout'] ?? 30);

        if (! $host || ! $credentialId) {
            return [];
        }

        $allowedDesc = empty($allowedCommands)
            ? 'all commands allowed by policy'
            : 'allowed: '.implode(', ', $allowedCommands);

        $teamId = $tool->team_id;
        $sshExecutor = $this->sshExecutor ?? app(SshExecutor::class);

        return [
            PrismTool::as('ssh_execute')
                ->for("Execute a command on {$username}@{$host}:{$port} via SSH. {$allowedDesc}")
                ->withStringParameter('command', 'The command to execute on the remote server')
                ->using(function (string $command) use ($teamId, $host, $port, $username, $credentialId, $allowedCommands, $timeout, $sshExecutor): string {
                    // Enforce allowed-commands whitelist at tool level
                    if (! empty($allowedCommands)) {
                        $binary = basename(explode(' ', trim($command))[0]);
                        if (! in_array($binary, $allowedCommands, true)) {
                            return "Error: Command '{$binary}' is not in the SSH tool allowlist.";
                        }
                    }

                    try {
                        $result = $sshExecutor->execute(
                            teamId: $teamId,
                            host: $host,
                            port: $port,
                            username: $username,
                            credentialId: $credentialId,
                            command: $command,
                            timeout: $timeout,
                        );

                        $prefix = $result->successful() ? '' : "Command failed (exit {$result->exitCode}):\n";

                        return $prefix.$result->output;
                    } catch (\Throwable $e) {
                        Log::error('SshExecutor error', ['error' => $e->getMessage()]);

                        return 'SSH error: '.$e->getMessage();
                    }
                }),
        ];
    }

    private function isPathAllowed(string $path, array $allowedPaths): bool
    {
        $resolvedPath = realpath($path) ?: $path;

        foreach ($allowedPaths as $allowed) {
            $resolvedAllowed = realpath($allowed) ?: $allowed;
            if (str_starts_with($resolvedPath, $resolvedAllowed)) {
                return true;
            }
        }

        return false;
    }
}
