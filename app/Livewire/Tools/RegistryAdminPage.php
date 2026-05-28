<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\CreateMcpRegistryEntryAction;
use App\Domain\Tool\Actions\InstallFromRegistryAction;
use App\Domain\Tool\Enums\RegistryTrustLevel;
use App\Domain\Tool\Models\McpServerRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('MCP Server Registry')]
class RegistryAdminPage extends Component
{
    public bool $showCreate = false;

    public string $name = '';

    public string $description = '';

    public string $transport = 'mcp_stdio';

    public string $connectionUrl = '';

    public string $connectionCommand = '';

    public string $trustLevel = 'community';

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'transport' => 'required|in:mcp_stdio,mcp_http',
            'connectionUrl' => 'required_if:transport,mcp_http|string|nullable',
            'connectionCommand' => 'required_if:transport,mcp_stdio|string|nullable',
            'trustLevel' => 'required|in:platform_trusted,verified,community',
        ];
    }

    public function openCreate(): void
    {
        $this->reset(['name', 'description', 'connectionUrl', 'connectionCommand']);
        $this->transport = 'mcp_stdio';
        $this->trustLevel = 'community';
        $this->showCreate = true;
    }

    public function save(CreateMcpRegistryEntryAction $action): void
    {
        Gate::authorize('edit-content');

        $this->validate();

        $connection = $this->transport === 'mcp_http'
            ? ['url' => $this->connectionUrl]
            : ['command' => $this->connectionCommand, 'args' => []];

        $action->execute([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'transport' => $this->transport,
            'connection' => $connection,
            'trust_level' => $this->trustLevel,
        ], auth()->id());

        $this->showCreate = false;
        session()->flash('success', 'Registry entry created.');
    }

    public function toggleActive(string $id): void
    {
        Gate::authorize('edit-content');

        $entry = McpServerRegistry::query()->findOrFail($id);
        $entry->update(['is_active' => ! $entry->is_active]);
    }

    public function install(string $id, InstallFromRegistryAction $action): void
    {
        $teamId = auth()->user()?->current_team_id;

        if ($teamId === null) {
            session()->flash('error', 'No active team.');

            return;
        }

        $entry = McpServerRegistry::query()->findOrFail($id);
        $tool = $action->execute($entry, $teamId);

        session()->flash('success', 'Installed as Tool: '.$tool->slug);
    }

    public function render()
    {
        return view('livewire.tools.registry-admin-page', [
            'entries' => McpServerRegistry::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'trustLevels' => RegistryTrustLevel::cases(),
        ]);
    }
}
