<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Services\McpRegistryClient;
use Livewire\Component;

class McpMarketplacePage extends Component
{
    public string $search = '';

    public int $page = 1;

    public bool $showInstallModal = false;

    public ?string $selectedServerId = null;

    public ?array $selectedServer = null;

    public string $installName = '';

    public string $installUrl = '';

    public string $installCommand = '';

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function openInstall(string $serverId): void
    {
        $client = app(McpRegistryClient::class);
        $server = $client->getServer($serverId);

        if (! $server) {
            session()->flash('error', 'Could not load server details.');

            return;
        }

        $this->selectedServerId = $serverId;
        $this->selectedServer = $server;
        $this->installName = $server['name'];

        // Pre-fill connection details
        if ($server['remote'] && ! empty($server['deployment_url'])) {
            $this->installUrl = $server['deployment_url'];
            $this->installCommand = '';
        } else {
            $this->installUrl = '';
            $this->installCommand = "npx -y {$serverId}";
        }

        $this->showInstallModal = true;
    }

    public function closeInstall(): void
    {
        $this->showInstallModal = false;
        $this->selectedServerId = null;
        $this->selectedServer = null;
    }

    private const ALLOWED_COMMANDS = ['npx', 'uvx', 'node', 'python3', 'python', 'docker', 'bunx'];

    public function install(): void
    {
        $rules = [
            'installName' => 'required|string|min:2|max:255',
        ];

        if ($this->installUrl) {
            $rules['installUrl'] = 'required|url|starts_with:https://';
        } else {
            $rules['installCommand'] = 'required|string|max:500';
        }

        $this->validate($rules);

        $team = auth()->user()->currentTeam;

        if ($this->installUrl) {
            // Reject private/internal IPs (SSRF guard)
            $host = parse_url($this->installUrl, PHP_URL_HOST);
            if ($host && $this->isPrivateHost($host)) {
                session()->flash('error', 'Cannot connect to private or internal addresses.');

                return;
            }

            $tool = app(CreateToolAction::class)->execute(
                teamId: $team->id,
                name: $this->installName,
                type: ToolType::McpHttp,
                description: $this->selectedServer['description'] ?? '',
                transportConfig: ['url' => $this->installUrl],
            );
        } else {
            // Validate command against whitelist
            $parts = explode(' ', $this->installCommand);
            $command = $parts[0] ?? 'npx';
            $args = array_slice($parts, 1);

            if (! in_array($command, self::ALLOWED_COMMANDS, true)) {
                session()->flash('error', 'Command must be one of: '.implode(', ', self::ALLOWED_COMMANDS));

                return;
            }

            // Reject shell metacharacters in args
            $forbidden = [';', '|', '&', '$', '`', '(', ')', '>', '<', '\\'];
            foreach ($args as $arg) {
                if (str_contains($arg, '..') || collect($forbidden)->contains(fn ($c) => str_contains($arg, $c))) {
                    session()->flash('error', 'Command arguments contain disallowed characters.');

                    return;
                }
            }

            $tool = app(CreateToolAction::class)->execute(
                teamId: $team->id,
                name: $this->installName,
                type: ToolType::McpStdio,
                description: $this->selectedServer['description'] ?? '',
                transportConfig: [
                    'command' => $command,
                    'args' => $args,
                    'env' => [],
                ],
            );
        }

        $serverName = $this->installName;
        $this->closeInstall();

        session()->flash('message', "Tool '{$serverName}' installed from MCP marketplace.");

        $this->redirect(route('tools.show', $tool));
    }

    private function isPrivateHost(string $host): bool
    {
        // Reject obvious private hostnames
        if (in_array($host, ['localhost', '0.0.0.0', ''], true) || str_ends_with($host, '.local')) {
            return true;
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false; // DNS resolution failed — let the HTTP client handle it
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function render()
    {
        $client = app(McpRegistryClient::class);
        $result = $client->search($this->search, $this->page);

        return view('livewire.tools.mcp-marketplace-page', [
            'servers' => $result['servers'],
            'pagination' => $result['pagination'],
            'error' => $result['error'],
        ])->layout('layouts.app', ['header' => 'MCP Marketplace']);
    }
}
