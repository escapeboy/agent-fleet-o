<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Enums\ToolType;
use Livewire\Component;

class CreateToolForm extends Component
{
    public int $step = 1;

    // Step 1: Basics
    public string $name = '';
    public string $description = '';
    public string $type = 'mcp_stdio';

    // Step 2: Transport Configuration
    // MCP stdio
    public string $mcpCommand = '';
    public string $mcpArgs = '';
    public string $mcpEnv = '';

    // MCP HTTP
    public string $mcpUrl = '';
    public string $mcpHeaders = '';

    // Built-in
    public string $builtInKind = 'bash';
    public string $allowedCommands = 'curl, jq, python3, node, grep, awk, sed, cat, echo, ls, find, wc, head, tail, sort, uniq';
    public string $allowedPaths = '/tmp/agent-workspace';
    public bool $readOnly = false;

    // Credentials (optional)
    public string $apiKey = '';

    // Tool definitions (MCP - JSON)
    public string $toolDefinitionsJson = '';

    // Settings
    public int $timeout = 30;

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'name' => 'required|min:2|max:255',
                'description' => 'max:1000',
                'type' => 'required|in:mcp_stdio,mcp_http,built_in',
            ]);
        }

        if ($this->step === 2) {
            $this->validateTransportConfig();
        }

        $this->step = min(3, $this->step + 1);
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    private function validateTransportConfig(): void
    {
        $type = ToolType::from($this->type);

        if ($type === ToolType::McpStdio) {
            $this->validate([
                'mcpCommand' => 'required|min:1',
            ]);
        }

        if ($type === ToolType::McpHttp) {
            $this->validate([
                'mcpUrl' => 'required|url',
            ]);
        }
    }

    public function save(): void
    {
        $team = auth()->user()->currentTeam;
        $type = ToolType::from($this->type);

        $transportConfig = $this->buildTransportConfig($type);
        $credentials = $this->buildCredentials();
        $toolDefinitions = $this->parseToolDefinitions();
        $settings = array_filter(['timeout' => $this->timeout]);

        app(CreateToolAction::class)->execute(
            teamId: $team->id,
            name: $this->name,
            type: $type,
            description: $this->description ?: null,
            transportConfig: $transportConfig,
            credentials: $credentials ?: null,
            toolDefinitions: $toolDefinitions ?: null,
            settings: $settings,
        );

        session()->flash('message', 'Tool created successfully!');

        $this->redirect(route('tools.index'));
    }

    private function buildTransportConfig(ToolType $type): array
    {
        return match ($type) {
            ToolType::McpStdio => [
                'command' => $this->mcpCommand,
                'args' => array_filter(array_map('trim', explode(',', $this->mcpArgs))),
                'env' => $this->parseKeyValuePairs($this->mcpEnv),
            ],
            ToolType::McpHttp => [
                'url' => $this->mcpUrl,
                'headers' => $this->parseKeyValuePairs($this->mcpHeaders),
            ],
            ToolType::BuiltIn => [
                'kind' => $this->builtInKind,
                'allowed_commands' => array_filter(array_map('trim', explode(',', $this->allowedCommands))),
                'allowed_paths' => array_filter(array_map('trim', explode(',', $this->allowedPaths))),
                'read_only' => $this->readOnly,
            ],
        };
    }

    private function buildCredentials(): array
    {
        return array_filter([
            'api_key' => $this->apiKey ?: null,
        ]);
    }

    private function parseToolDefinitions(): ?array
    {
        if (empty($this->toolDefinitionsJson)) {
            return null;
        }

        $decoded = json_decode($this->toolDefinitionsJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function parseKeyValuePairs(string $input): array
    {
        if (empty(trim($input))) {
            return [];
        }

        $pairs = [];
        foreach (explode("\n", $input) as $line) {
            $line = trim($line);
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $pairs[trim($key)] = trim($value);
            }
        }

        return $pairs;
    }

    public function render()
    {
        return view('livewire.tools.create-tool-form', [
            'types' => ToolType::cases(),
            'builtInKinds' => BuiltInToolKind::cases(),
        ])->layout('layouts.app', ['header' => 'Create Tool']);
    }
}
