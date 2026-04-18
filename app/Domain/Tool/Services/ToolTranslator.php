<?php

namespace App\Domain\Tool\Services;

use App\Domain\Agent\Services\DockerSandboxExecutor;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Actions\CaptureScreenshotArtifactsAction;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Exceptions\BrowserTaskFailedException;
use App\Domain\Tool\Exceptions\BrowserTaskTimeoutException;
use App\Domain\Tool\Exceptions\ResultAsAnswerException;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Cache;
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
    public function toPrismTools(Tool $tool, array $overrides = [], ?array $orgPolicy = null, ?SandboxedWorkspace $workspace = null): array
    {
        if ($tool->isBuiltIn()) {
            return $this->translateBuiltInTool($tool, $overrides, $orgPolicy, $workspace);
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
        // Cache MCP tool definitions using content hash (not updated_at) to avoid
        // false invalidation when only health_status or other non-definition fields change.
        $rawDefs = $tool->tool_definitions ?? [];
        $contentHash = md5(json_encode($rawDefs));
        $cacheKey = "mcp_tools:{$tool->id}:{$contentHash}";

        $definitions = Cache::remember($cacheKey, 3600, fn () => $rawDefs);
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

            // MCP tool handler: proxy the call to the appropriate MCP client
            $isBridge = $tool->type === ToolType::McpBridge;
            $isStdio = $tool->type === ToolType::McpStdio;
            $serverUrl = $tool->transport_config['url'] ?? null;
            $credentials = (array) $tool->credentials;
            $authHeader = $credentials['api_key'] ?? $credentials['bearer_token'] ?? null;
            // Merge transport_config headers (e.g. stored Authorization) with credentials-derived header
            $configHeaders = $tool->transport_config['headers'] ?? [];
            $mcpHeaders = array_merge(
                $configHeaders,
                $authHeader ? ['Authorization' => "Bearer {$authHeader}"] : [],
            );
            $toolModel = $tool;
            $defName = $name;
            $paramNames = array_keys($properties);

            $resultAsAnswer = $toolModel->result_as_answer;

            // If result_as_answer is enabled, register a custom error handler that
            // re-throws ResultAsAnswerException while handling other errors normally.
            // PrismPHP's Tool::handle() catches all Throwable — without this, our
            // exception would be swallowed and turned into an error message string.
            if ($resultAsAnswer) {
                $prismTool->failed(function (\Throwable $e) {
                    if ($e instanceof ResultAsAnswerException) {
                        throw $e;
                    }

                    return "Error: {$e->getMessage()}";
                });
            }

            $prismTool->using(function (...$namedArgs) use ($isBridge, $isStdio, $serverUrl, $defName, $toolModel, $mcpHeaders, $resultAsAnswer): string {
                // PrismPHP passes arguments as named parameters (PHP 8 spread of associative array).
                // Capture via variadic ...$namedArgs — the array is already keyed by param name.
                // Filter out nulls to avoid sending empty optional params to MCP server.
                $arguments = array_filter($namedArgs, fn ($v) => $v !== null);

                try {
                    if ($isBridge) {
                        $result = app(McpBridgeClient::class)->callTool($toolModel, $defName, $arguments);
                    } elseif ($isStdio) {
                        $result = app(McpStdioClient::class)->callTool($toolModel, $defName, $arguments);
                    } elseif (! $serverUrl) {
                        return "Error: Tool '{$defName}' on server '{$toolModel->name}' has no URL configured.";
                    } else {
                        $result = app(McpHttpClient::class)->callTool($serverUrl, $defName, $arguments, $mcpHeaders);
                    }

                    // If tool has result_as_answer, short-circuit the LLM loop
                    if ($resultAsAnswer) {
                        throw new ResultAsAnswerException($result, $defName);
                    }

                    return $result;
                } catch (ResultAsAnswerException $e) {
                    throw $e; // Re-throw — must not be caught by generic handler
                } catch (\Throwable $e) {
                    $client = $isBridge ? 'McpBridgeClient' : ($isStdio ? 'McpStdioClient' : 'McpHttpClient');
                    Log::error("{$client} error", [
                        'tool' => $toolModel->name,
                        'function' => $defName,
                        'error' => $e->getMessage(),
                    ]);

                    return "Error calling {$defName}: {$e->getMessage()}";
                }
            });

            $tools[] = $prismTool;
        }

        return $tools;
    }

    /**
     * Build PrismPHP Tools for built-in host capabilities.
     */
    private function translateBuiltInTool(Tool $tool, array $overrides, ?array $orgPolicy = null, ?SandboxedWorkspace $workspace = null): array
    {
        $kind = BuiltInToolKind::tryFrom($tool->transport_config['kind'] ?? 'bash');

        return match ($kind) {
            BuiltInToolKind::Bash => $this->buildBashTools($tool, $overrides, $orgPolicy, $workspace),
            BuiltInToolKind::Filesystem => $this->buildFilesystemTools($tool, $overrides, $workspace),
            BuiltInToolKind::Browser => $this->buildBrowserTools($tool),
            BuiltInToolKind::Ssh => $this->buildSshTools($tool, $overrides),
            BuiltInToolKind::BrowserRelay => $this->buildBrowserRelayTools($tool),
            BuiltInToolKind::ComputerUse => $this->buildComputerUseTools($tool),
            BuiltInToolKind::BrowserUseCloud => $this->buildBrowserUseCloudTools($tool),
            default => [],
        };
    }

    private function buildBashTools(Tool $tool, array $overrides, ?array $orgPolicy = null, ?SandboxedWorkspace $workspace = null): array
    {
        $config = $tool->transport_config;
        $allowedCommands = $config['allowed_commands'] ?? [
            'curl', 'jq', 'python3', 'node', 'grep', 'awk', 'sed',
            'cat', 'echo', 'ls', 'find', 'wc', 'head', 'tail', 'sort', 'uniq',
        ];
        $allowedPaths = $config['allowed_paths'] ?? ['/tmp/agent-workspace'];
        $timeout = $tool->settings['timeout'] ?? 30;
        $maxOutputChars = 10000;
        // Credential env vars injected by ResolveAgentToolsAction (from agent's tool credentials)
        $credentialEnv = $config['env'] ?? [];

        $pathDescription = $workspace ? 'sandbox workspace' : implode(', ', $allowedPaths);
        $commandDescription = implode(', ', $allowedCommands);

        return [
            PrismTool::as('bash_execute')
                ->for("Execute a shell command. Allowed commands: {$commandDescription}. Working directory restricted to: {$pathDescription}")
                ->withStringParameter('command', 'The shell command to execute')
                ->withStringParameter('working_directory', 'Working directory relative to sandbox root (sandbox mode) or absolute path', required: false)
                ->using(function (string $command, ?string $working_directory = null) use ($allowedCommands, $allowedPaths, $timeout, $maxOutputChars, $orgPolicy, $workspace, $tool, $credentialEnv): string {
                    // just-bash sidecar mode: route through the Node.js bash-sidecar container
                    if ($workspace && config('agent.bash_sandbox_mode') === 'just_bash') {
                        // Execution-time plan check: allows cloud to block on plan downgrade.
                        // Cloud registers 'bash.plan_gate' as a callable (team_id → bool).
                        if ($tool->team_id && app()->bound('bash.plan_gate')) {
                            $gate = app('bash.plan_gate');
                            if (! $gate($tool->team_id)) {
                                return 'Error: Sandboxed bash requires a paid plan (Starter or above). Please upgrade to continue using this tool.';
                            }
                        }

                        $policy = app(CommandSecurityPolicy::class);
                        $validation = $policy->validate(
                            $command, null, $allowedCommands, [], null, null, $orgPolicy,
                        );

                        if (! $validation->allowed) {
                            return "Error: {$validation->reason}";
                        }

                        $sessionId = $workspace->sidecarSessionId();
                        if ($sessionId === null) {
                            return 'Error: Bash sandbox session not initialised';
                        }

                        // Cap timeout to the plan's sandboxed_bash_timeout limit.
                        // Cloud registers 'bash.timeout_gate' as a callable (team_id → int seconds).
                        $effectiveTimeout = $timeout;
                        if ($tool->team_id && app()->bound('bash.timeout_gate')) {
                            $planTimeout = app('bash.timeout_gate')($tool->team_id);
                            if ($planTimeout > 0) {
                                $effectiveTimeout = min($effectiveTimeout, $planTimeout);
                            }
                        }

                        $output = app(BashSidecarClient::class)->run($sessionId, $command, $effectiveTimeout * 1000);

                        // Audit every command executed in production (cloud) environment
                        if (app()->environment('production') && $tool->team_id) {
                            $ocsf = OcsfMapper::classify('bash.command_executed');
                            AuditEntry::create([
                                'team_id' => $tool->team_id,
                                'event' => 'bash.command_executed',
                                'ocsf_class_uid' => $ocsf['class_uid'],
                                'ocsf_severity_id' => $ocsf['severity_id'],
                                'properties' => [
                                    'command_preview' => substr($command, 0, 200),
                                    'exit_code' => $output['exitCode'],
                                    'session_id' => $sessionId,
                                    'tool_id' => $tool->id,
                                ],
                                'created_at' => now(),
                            ]);
                        }

                        if ($output['stdout'] !== '') {
                            return $output['stdout'];
                        }

                        if ($output['stderr'] !== '') {
                            return "stderr: {$output['stderr']}";
                        }

                        return "(exit {$output['exitCode']})";
                    }

                    // Docker sandbox mode: run in isolated container
                    if ($workspace && config('agent.bash_sandbox_mode') === 'docker') {
                        $executor = app(DockerSandboxExecutor::class);
                        $networkPolicy = $tool->network_policy; // nullable array from Tool model
                        $result = $executor->execute($command, $workspace, $timeout, $credentialEnv ?: null, $networkPolicy);

                        return $result['stdout'] ?: $result['stderr'] ?: "(exit {$result['exit_code']})";
                    }

                    // PHP mode with sandbox: override working directory to sandbox root
                    if ($workspace) {
                        $cwd = $working_directory
                            ? $workspace->resolve($working_directory)
                            : $workspace->root();

                        return $this->executeBashCommand($command, $cwd, $allowedCommands, [$workspace->root()], $timeout, $maxOutputChars, orgSecurityPolicy: $orgPolicy, credentialEnv: $credentialEnv ?: null);
                    }

                    // Legacy mode: use configured allowed paths
                    return $this->executeBashCommand($command, $working_directory, $allowedCommands, $allowedPaths, $timeout, $maxOutputChars, orgSecurityPolicy: $orgPolicy, credentialEnv: $credentialEnv ?: null);
                }),
        ];
    }

    /**
     * @param  array<string, string>|null  $credentialEnv  Credential env vars to inject into process
     */
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
        ?array $credentialEnv = null,
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

        $process = Process::timeout($timeout)->path($cwd);

        if (! empty($credentialEnv)) {
            $process = $process->env($credentialEnv);
        }

        $result = $process->run($command);

        if ($result->successful()) {
            $output = $result->output();

            return mb_strlen($output) > $maxOutputChars
                ? mb_substr($output, 0, $maxOutputChars)."\n... [output truncated at {$maxOutputChars} chars]"
                : $output;
        }

        $errorOutput = mb_substr($result->errorOutput(), 0, 2000);

        return "Command failed (exit {$result->exitCode()}): {$errorOutput}";
    }

    private function buildFilesystemTools(Tool $tool, array $overrides, ?SandboxedWorkspace $workspace = null): array
    {
        $config = $tool->transport_config;
        $allowedPaths = $config['allowed_paths'] ?? ['/tmp/agent-workspace'];
        $readOnly = $config['read_only'] ?? false;
        $maxReadSize = 50000;
        $pathDescription = $workspace ? 'sandbox workspace' : implode(', ', $allowedPaths);

        $tools = [];

        // Read file
        $tools[] = PrismTool::as('file_read')
            ->for("Read a file's contents. Paths restricted to: {$pathDescription}")
            ->withStringParameter('path', 'Path to the file (relative to sandbox root in sandbox mode, absolute otherwise)')
            ->using(function (string $path) use ($allowedPaths, $maxReadSize, $workspace): string {
                try {
                    $absolute = $workspace ? $workspace->resolve($path) : $path;
                } catch (\OutOfBoundsException $e) {
                    return "Error: {$e->getMessage()}";
                }

                if (! $workspace && ! $this->isPathAllowed($absolute, $allowedPaths)) {
                    return "Error: Path '{$path}' is outside allowed directories.";
                }
                if (! file_exists($absolute)) {
                    return "Error: File not found: {$path}";
                }
                $content = file_get_contents($absolute);
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
            ->withStringParameter('path', 'Path to the directory (relative to sandbox root in sandbox mode, absolute otherwise)')
            ->using(function (string $path) use ($allowedPaths, $workspace): string {
                try {
                    $absolute = $workspace ? $workspace->resolve($path) : $path;
                } catch (\OutOfBoundsException $e) {
                    return "Error: {$e->getMessage()}";
                }

                if (! $workspace && ! $this->isPathAllowed($absolute, $allowedPaths)) {
                    return "Error: Path '{$path}' is outside allowed directories.";
                }
                if (! is_dir($absolute)) {
                    return "Error: Not a directory: {$path}";
                }
                $entries = scandir($absolute);

                return implode("\n", array_diff($entries ?: [], ['.', '..']));
            });

        // Write file (if not read-only)
        if (! $readOnly) {
            $tools[] = PrismTool::as('file_write')
                ->for("Write content to a file. Paths restricted to: {$pathDescription}")
                ->withStringParameter('path', 'Path to the file (relative to sandbox root in sandbox mode, absolute otherwise)')
                ->withStringParameter('content', 'The content to write')
                ->using(function (string $path, string $content) use ($allowedPaths, $workspace): string {
                    try {
                        $absolute = $workspace ? $workspace->resolve($path) : $path;
                    } catch (\OutOfBoundsException $e) {
                        return "Error: {$e->getMessage()}";
                    }

                    if (! $workspace && ! $this->isPathAllowed($absolute, $allowedPaths)) {
                        return "Error: Path '{$path}' is outside allowed directories.";
                    }
                    $dir = dirname($absolute);
                    if (! is_dir($dir)) {
                        @mkdir($dir, 0700, true);
                    }
                    $bytes = file_put_contents($absolute, $content);

                    return $bytes !== false
                        ? "Written {$bytes} bytes to {$path}"
                        : "Error: Could not write to {$path}";
                });
        }

        return $tools;
    }

    private function buildBrowserTools(Tool $tool): array
    {
        $mode = config('agent.browser_sandbox_mode', 'disabled');

        if ($mode === 'disabled') {
            // Return an inert tool that explains the plan requirement instead of nothing,
            // so the LLM can surface a clear message rather than silently failing.
            return [
                PrismTool::as('browser_task')
                    ->for('Autonomously browse the web to complete a task (navigate, click, fill forms, extract data)')
                    ->withStringParameter('task', 'Natural language description of the browsing task to perform')
                    ->withStringParameter('start_url', 'Optional starting URL', required: false)
                    ->withNumberParameter('max_steps', 'Maximum number of browser steps (default: 10)', required: false)
                    ->using(fn () => 'Error: Browser automation requires a paid plan. Please upgrade to Starter or above.'),
            ];
        }

        $toolModel = $tool;

        return [
            PrismTool::as('browser_task')
                ->for('Autonomously browse the web to complete a task (navigate, click, fill forms, extract data). Returns the extracted result as text. Set headless=false for sites with anti-bot protection (Reddit, Cloudflare-protected sites) — runs in a virtual display.')
                ->withStringParameter('task', 'Natural language description of the browsing task to perform')
                ->withStringParameter('start_url', 'Optional starting URL to begin from', required: false)
                ->withNumberParameter('max_steps', 'Maximum number of browser steps (default: 10, plan-capped)', required: false)
                ->withStringParameter('headless', 'Run browser in headless mode. Pass "true" (default) or "false". Use "false" for sites with anti-bot detection (Reddit, Cloudflare challenges) — uses a real visible Chrome in a virtual display.', required: false)
                ->using(function (string $task, ?string $start_url = null, ?int $max_steps = null, ?string $headless = null) use ($mode, $toolModel): string {
                    // Execution-time plan gate — cloud registers 'browser.plan_gate' as a callable.
                    if ($toolModel->team_id && app()->bound('browser.plan_gate')) {
                        $gate = app('browser.plan_gate');
                        if (! $gate($toolModel->team_id)) {
                            return 'Error: Browser automation requires a paid plan (Starter or above). Please upgrade to continue.';
                        }
                    }

                    // Cap max_steps to the plan limit.
                    $effectiveMaxSteps = $max_steps ?? 10;
                    if ($toolModel->team_id && app()->bound('browser.max_steps_gate')) {
                        $planMaxSteps = app('browser.max_steps_gate')($toolModel->team_id);
                        if ($planMaxSteps > 0) {
                            $effectiveMaxSteps = min($effectiveMaxSteps, $planMaxSteps);
                        }
                    }

                    // Resolve timeout from plan.
                    $timeoutSeconds = 120;
                    if ($toolModel->team_id && app()->bound('browser.timeout_gate')) {
                        $planTimeout = app('browser.timeout_gate')($toolModel->team_id);
                        if ($planTimeout > 0) {
                            $timeoutSeconds = $planTimeout;
                        }
                    }

                    $options = [
                        'max_steps' => $effectiveMaxSteps,
                        'timeout_seconds' => $timeoutSeconds,
                    ];

                    if ($start_url) {
                        $options['start_url'] = $start_url;
                    }

                    // Resolve API key from tool credentials or env fallback.
                    /** @var array<string, mixed> $credentials */
                    $credentials = (array) $toolModel->credentials;
                    $apiKey = $credentials['api_key'] ?? config('agent.browser_use_cloud_api_key', '');

                    // Resolve BYOK credentials for the sidecar LLM.
                    if ($mode === 'sidecar' && $toolModel->team_id) {
                        $byok = $this->resolveBrowserByok($toolModel->team_id);
                        if ($byok) {
                            $options['llm_api_key'] = $byok['api_key'];
                            $options['llm_provider'] = $byok['provider'];
                            $options['llm_model'] = $byok['model'];
                        }
                    }

                    // Per-tool proxy — resolved from linked Credential (type: proxy).
                    $proxyUrl = $this->resolveProxyUrl($toolModel);
                    if ($proxyUrl) {
                        $options['proxy_url'] = $proxyUrl;
                    }

                    // Remote browser via CDP (e.g. OpenClaw real Chrome).
                    $cdpUrl = $toolModel->transport_config['cdp_url'] ?? null;
                    if ($cdpUrl) {
                        $options['cdp_url'] = $cdpUrl;
                    }

                    // Headless mode — agent-controlled, falls back to tool config default.
                    if ($headless !== null && $headless !== '') {
                        $options['headless'] = filter_var($headless, FILTER_VALIDATE_BOOLEAN);
                    } elseif (isset($toolModel->transport_config['headless'])) {
                        $options['headless'] = (bool) $toolModel->transport_config['headless'];
                    }

                    try {
                        if ($mode === 'cloud') {
                            $result = app(BrowserUseCloudClient::class, ['apiKey' => $apiKey])->run($task, $options);
                        } elseif ($mode === 'sidecar') {
                            $result = app(BrowserSidecarClient::class)->run($task, $options);
                        } else {
                            return "Error: Unknown browser sandbox mode: {$mode}";
                        }
                    } catch (BrowserTaskTimeoutException $e) {
                        return "Error: {$e->getMessage()}";
                    } catch (BrowserTaskFailedException $e) {
                        return "Error: {$e->getMessage()}";
                    } catch (\Throwable $e) {
                        Log::error('BrowserTool error', ['error' => $e->getMessage(), 'team_id' => $toolModel->team_id]);

                        return 'Error: Browser task encountered an unexpected error. Please try again.';
                    }

                    // Capture screenshots as artifacts when available and experiment context is set.
                    $screenshots = $result['screenshots'] ?? [];
                    if (! empty($screenshots) && $toolModel->team_id && app()->bound('ai.current_experiment_id')) {
                        try {
                            app(CaptureScreenshotArtifactsAction::class)->execute(
                                screenshots: $screenshots,
                                teamId: $toolModel->team_id,
                                experimentId: app('ai.current_experiment_id'),
                                agentId: app()->bound('ai.current_agent_id') ? app('ai.current_agent_id') : null,
                                stepIndex: 1,
                            );
                        } catch (\Throwable $e) {
                            Log::warning('ToolTranslator: screenshot artifact capture failed', [
                                'error' => $e->getMessage(),
                                'team_id' => $toolModel->team_id,
                            ]);
                        }
                    }

                    // Audit every browser task in production.
                    if (app()->environment('production') && $toolModel->team_id) {
                        $ocsf = OcsfMapper::classify('browser.task_executed');
                        AuditEntry::create([
                            'team_id' => $toolModel->team_id,
                            'event' => 'browser.task_executed',
                            'ocsf_class_uid' => $ocsf['class_uid'],
                            'ocsf_severity_id' => $ocsf['severity_id'],
                            'properties' => [
                                'task_preview' => substr($task, 0, 200),
                                'status' => $result['status'],
                                'duration_ms' => $result['duration_ms'],
                                'steps_taken' => $result['steps_taken'],
                                'mode' => $mode,
                                'tool_id' => $toolModel->id,
                            ],
                            'created_at' => now(),
                        ]);
                    }

                    $output = $result['output'];

                    // Truncate to avoid context overflow (50k chars ≈ ~12k tokens).
                    if (mb_strlen($output) > 50000) {
                        $output = mb_substr($output, 0, 50000)."\n... [output truncated]";
                    }

                    return $output ?: '(Task completed with no text output)';
                }),
        ];
    }

    /**
     * Explicit "browser_use_cloud" tool kind — always routes to the browser-use
     * Cloud REST API (cloud.browser-use.com) regardless of the global
     * browser_sandbox_mode. Lets a team provision a dedicated Cloud-backed
     * browser tool with its own BYOK api_key stored in $tool->credentials,
     * independent of the platform's default browser mode.
     *
     * @see https://docs.browser-use.com/cloud/api-reference
     */
    private function buildBrowserUseCloudTools(Tool $tool): array
    {
        $toolModel = $tool;

        return [
            PrismTool::as('browser_task')
                ->for('Autonomously browse the web to complete a task via browser-use Cloud (cloud.browser-use.com). Natural language task description, returns the extracted result as text. Good for: form filling, data extraction, multi-step navigation, sites that need a real browser.')
                ->withStringParameter('task', 'Natural language description of the browsing task to perform')
                ->withStringParameter('start_url', 'Optional starting URL to begin from', required: false)
                ->withNumberParameter('max_steps', 'Maximum number of browser steps (default: 10)', required: false)
                ->using(function (string $task, ?string $start_url = null, ?int $max_steps = null) use ($toolModel): string {
                    // Execution-time plan gate — cloud registers 'browser.plan_gate' as a callable.
                    if ($toolModel->team_id && app()->bound('browser.plan_gate')) {
                        $gate = app('browser.plan_gate');
                        if (! $gate($toolModel->team_id)) {
                            return 'Error: Browser automation requires a paid plan (Starter or above). Please upgrade to continue.';
                        }
                    }

                    $effectiveMaxSteps = $max_steps ?? 10;
                    if ($toolModel->team_id && app()->bound('browser.max_steps_gate')) {
                        $planMaxSteps = app('browser.max_steps_gate')($toolModel->team_id);
                        if ($planMaxSteps > 0) {
                            $effectiveMaxSteps = min($effectiveMaxSteps, $planMaxSteps);
                        }
                    }

                    $timeoutSeconds = 120;
                    if ($toolModel->team_id && app()->bound('browser.timeout_gate')) {
                        $planTimeout = app('browser.timeout_gate')($toolModel->team_id);
                        if ($planTimeout > 0) {
                            $timeoutSeconds = $planTimeout;
                        }
                    }

                    $options = [
                        'max_steps' => $effectiveMaxSteps,
                        'timeout_seconds' => $timeoutSeconds,
                    ];

                    if ($start_url) {
                        $options['start_url'] = $start_url;
                    }

                    // API key resolution order:
                    //  1. team-owned tool: encrypted $toolModel->credentials['api_key']
                    //  2. platform template activated per-team: credential_overrides
                    //     are merged into transport_config.env by ResolveAgentToolsAction
                    //  3. platform-wide env var BROWSER_USE_CLOUD_API_KEY (fallback)
                    /** @var array<string, mixed> $credentials */
                    $credentials = (array) $toolModel->credentials;
                    $envBag = $toolModel->transport_config['env'] ?? [];
                    $apiKey = $credentials['api_key']
                        ?? ($envBag['api_key'] ?? null)
                        ?? ($envBag['API_KEY'] ?? null)
                        ?? config('agent.browser_use_cloud_api_key', '');

                    if ($apiKey === '') {
                        return 'Error: browser-use Cloud requires an API key. Add it to the tool credentials (field: api_key) — get one at https://cloud.browser-use.com/settings.';
                    }

                    try {
                        $result = app(BrowserUseCloudClient::class, ['apiKey' => $apiKey])->run($task, $options);
                    } catch (BrowserTaskTimeoutException $e) {
                        return "Error: {$e->getMessage()}";
                    } catch (BrowserTaskFailedException $e) {
                        return "Error: {$e->getMessage()}";
                    } catch (\Throwable $e) {
                        Log::error('BrowserUseCloudTool error', ['error' => $e->getMessage(), 'team_id' => $toolModel->team_id]);

                        return 'Error: Browser task encountered an unexpected error. Please try again.';
                    }

                    // Capture screenshots as artifacts when available.
                    $screenshots = $result['screenshots'] ?? [];
                    if (! empty($screenshots) && $toolModel->team_id && app()->bound('ai.current_experiment_id')) {
                        try {
                            app(CaptureScreenshotArtifactsAction::class)->execute(
                                screenshots: $screenshots,
                                teamId: $toolModel->team_id,
                                experimentId: app('ai.current_experiment_id'),
                                agentId: app()->bound('ai.current_agent_id') ? app('ai.current_agent_id') : null,
                                stepIndex: 1,
                            );
                        } catch (\Throwable $e) {
                            Log::warning('ToolTranslator: screenshot artifact capture failed', [
                                'error' => $e->getMessage(),
                                'team_id' => $toolModel->team_id,
                            ]);
                        }
                    }

                    // Audit every browser-use Cloud task in production.
                    if (app()->environment('production') && $toolModel->team_id) {
                        $ocsf = OcsfMapper::classify('browser.task_executed');
                        AuditEntry::create([
                            'team_id' => $toolModel->team_id,
                            'event' => 'browser.task_executed',
                            'ocsf_class_uid' => $ocsf['class_uid'],
                            'ocsf_severity_id' => $ocsf['severity_id'],
                            'properties' => [
                                'tool_id' => $toolModel->id,
                                'kind' => 'browser_use_cloud',
                                'task_length' => strlen($task),
                                'start_url' => $start_url,
                                'max_steps' => $effectiveMaxSteps,
                                'status' => $result['status'] ?? 'unknown',
                                'duration_ms' => $result['duration_ms'] ?? 0,
                                'steps_taken' => $result['steps_taken'] ?? 0,
                            ],
                        ]);
                    }

                    $output = $result['output'];

                    if (mb_strlen($output) > 50000) {
                        $output = mb_substr($output, 0, 50000)."\n... [output truncated]";
                    }

                    return $output ?: '(Task completed with no text output)';
                }),
        ];
    }

    private function buildBrowserRelayTools(Tool $tool): array
    {
        $toolDescription = 'Control the user\'s local browser via their connected relay agent and BrowserMCP extension. '
            .'Available actions: browser_navigate(url), browser_snapshot (read DOM/page state), browser_screenshot, '
            .'browser_click(element,ref), browser_type(element,ref,text), browser_select_option(element,ref,values[]), '
            .'browser_press_key(key), browser_hover(element,ref), browser_drag, browser_go_back, browser_go_forward, '
            .'browser_wait(time?), browser_get_console_logs. '
            .'Always call browser_snapshot first to see the current page and get element refs for interactions.';

        if (! app()->bound('browser_relay.dispatcher')) {
            return [
                PrismTool::as('browser_relay_execute')
                    ->for($toolDescription)
                    ->withStringParameter('action', 'BrowserMCP tool name (e.g. browser_navigate, browser_snapshot, browser_click)')
                    ->withStringParameter('params_json', 'Tool parameters as a JSON object string (e.g. {"url":"https://example.com"}). Pass empty string for tools with no parameters.', required: false)
                    ->using(fn () => 'Error: Browser relay is not available in this environment. Please configure a relay agent.'),
            ];
        }

        $teamId = $tool->team_id;
        $dispatcher = app('browser_relay.dispatcher');

        return [
            PrismTool::as('browser_relay_execute')
                ->for($toolDescription)
                ->withStringParameter('action', 'BrowserMCP tool name (e.g. browser_navigate, browser_snapshot, browser_click, browser_type, browser_screenshot)')
                ->withStringParameter('params_json', 'Tool parameters as a JSON object string (e.g. {"url":"https://example.com"} for browser_navigate, {"element":"Submit button","ref":"button#submit","text":"hello"} for browser_type). Pass empty string for tools with no parameters.', required: false)
                ->using(function (string $action, ?string $params_json = null) use ($teamId, $dispatcher): string {
                    $params = [];

                    if ($params_json && $params_json !== '') {
                        $decoded = json_decode($params_json, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $params = $decoded;
                        }
                    }

                    try {
                        $result = $dispatcher($teamId, $action, $params);
                    } catch (\RuntimeException $e) {
                        return 'Error: '.$e->getMessage();
                    } catch (\Throwable $e) {
                        Log::error('BrowserRelayTool error', ['action' => $action, 'error' => $e->getMessage(), 'team_id' => $teamId]);

                        return 'Error: Browser relay encountered an unexpected error. Is the relay agent running?';
                    }

                    if (empty($result)) {
                        return '(no output)';
                    }

                    // BrowserMCP returns a content array — extract text items
                    $output = '';
                    foreach ((array) $result as $item) {
                        if (is_array($item)) {
                            if (($item['type'] ?? '') === 'text' || isset($item['text'])) {
                                $output .= $item['text'] ?? '';
                            }
                        } elseif (is_string($item)) {
                            $output .= $item;
                        }
                    }

                    if ($output === '') {
                        $output = json_encode($result);
                    }

                    // Truncate to avoid context overflow
                    if (mb_strlen($output) > 50000) {
                        $output = mb_substr($output, 0, 50000)."\n... [output truncated]";
                    }

                    return $output;
                }),
        ];
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

                        // Also validate the full command string for dangerous shell patterns —
                        // the binary check alone does not prevent shell injection in arguments.
                        $policy = app(CommandSecurityPolicy::class);
                        $validation = $policy->validate(
                            $command,
                            workingDirectory: null,
                            toolAllowedCommands: $allowedCommands,
                            toolAllowedPaths: [],
                        );
                        if (! $validation->allowed) {
                            return "Error: {$validation->reason}";
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
            $allowedWithSlash = rtrim($resolvedAllowed, '/').'/';
            if ($resolvedPath === $resolvedAllowed || str_starts_with($resolvedPath.'/', $allowedWithSlash)) {
                return true;
            }
        }

        return false;
    }

    private function buildComputerUseTools(Tool $tool): array
    {
        // Base: always disabled — computer use requires a sandboxed container with X11 display.
        // Cloud overrides this by registering 'computer_use.executor' in the service container.
        if (app()->bound('computer_use.executor')) {
            return app('computer_use.executor')($tool);
        }

        return [
            PrismTool::as('computer_screenshot')
                ->for('Capture a screenshot of the current screen (computer use — not available in this environment)')
                ->using(fn () => json_encode([
                    'error' => 'Computer use requires a Pro plan with sandbox enabled. Please upgrade to access desktop automation.',
                ])),
        ];
    }

    /**
     * Resolve BYOK LLM credentials for the browser sidecar.
     * Prefers anthropic, falls back to openai.
     *
     * @return array{api_key: string, provider: string}|null
     */
    private function resolveBrowserByok(string $teamId): ?array
    {
        foreach (['anthropic', 'openai'] as $provider) {
            $credential = TeamProviderCredential::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('provider', $provider)
                ->where('is_active', true)
                ->first();

            if ($credential && ! empty($credential->credentials['api_key'])) {
                $defaultModel = match ($provider) {
                    'anthropic' => 'claude-sonnet-4-5-20250514',
                    'openai' => 'gpt-4o',
                    default => 'gpt-4o',
                };

                return [
                    'api_key' => $credential->credentials['api_key'],
                    'provider' => $provider,
                    'model' => $defaultModel,
                ];
            }
        }

        return null;
    }

    /**
     * Resolve a proxy URL from the tool's transport_config.proxy_credential_id,
     * or fall back to a raw transport_config.proxy_url string.
     */
    private function resolveProxyUrl(Tool $tool): ?string
    {
        $config = $tool->transport_config ?? [];

        // Credential-based proxy (preferred).
        $credentialId = $config['proxy_credential_id'] ?? null;
        if ($credentialId && $tool->team_id) {
            $credential = Credential::withoutGlobalScopes()
                ->where('id', $credentialId)
                ->where('team_id', $tool->team_id)
                ->first();

            if ($credential) {
                $data = $credential->secret_data ?? [];
                $protocol = $data['protocol'] ?? 'socks5';
                $host = $data['host'] ?? '';
                $port = $data['port'] ?? 1080;
                $username = $data['username'] ?? null;
                $password = $data['password'] ?? null;

                if ($host) {
                    $auth = ($username && $password) ? "{$username}:{$password}@" : '';

                    return "{$protocol}://{$auth}{$host}:{$port}";
                }
            }
        }

        // Fallback: raw proxy_url in transport_config (for manual/legacy config).
        return $config['proxy_url'] ?? null;
    }
}
