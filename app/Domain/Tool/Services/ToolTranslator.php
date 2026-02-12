<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Process;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class ToolTranslator
{
    /**
     * Convert a Tool model into PrismPHP Tool objects.
     *
     * @return array<PrismToolObject>
     */
    public function toPrismTools(Tool $tool, array $overrides = []): array
    {
        if ($tool->isBuiltIn()) {
            return $this->translateBuiltInTool($tool, $overrides);
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
    private function translateBuiltInTool(Tool $tool, array $overrides): array
    {
        $kind = BuiltInToolKind::tryFrom($tool->transport_config['kind'] ?? 'bash');

        return match ($kind) {
            BuiltInToolKind::Bash => $this->buildBashTools($tool, $overrides),
            BuiltInToolKind::Filesystem => $this->buildFilesystemTools($tool, $overrides),
            BuiltInToolKind::Browser => [],
            default => [],
        };
    }

    private function buildBashTools(Tool $tool, array $overrides): array
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
                ->using(function (string $command, ?string $working_directory = null) use ($allowedCommands, $allowedPaths, $timeout, $maxOutputChars): string {
                    return $this->executeBashCommand($command, $working_directory, $allowedCommands, $allowedPaths, $timeout, $maxOutputChars);
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
    ): string {
        // Validate command starts with an allowed binary
        $binary = explode(' ', trim($command))[0];
        $binary = basename($binary); // Strip path prefixes like /usr/bin/

        if (! in_array($binary, $allowedCommands)) {
            return "Error: Command '{$binary}' is not allowed. Allowed: ".implode(', ', $allowedCommands);
        }

        // Block dangerous patterns
        $dangerousPatterns = ['| bash', '| sh', '| zsh', '$(', '`', '&& rm', '; rm', 'sudo ', 'chmod ', 'chown '];
        foreach ($dangerousPatterns as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return "Error: Command contains blocked pattern: '{$pattern}'";
            }
        }

        // Validate working directory
        $cwd = $workingDirectory ?? $allowedPaths[0] ?? '/tmp';
        $resolvedCwd = realpath($cwd) ?: $cwd;
        $isAllowed = false;
        foreach ($allowedPaths as $path) {
            $resolvedPath = realpath($path) ?: $path;
            if (str_starts_with($resolvedCwd, $resolvedPath)) {
                $isAllowed = true;
                break;
            }
        }
        if (! $isAllowed) {
            return "Error: Working directory '{$cwd}' is outside allowed paths.";
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
