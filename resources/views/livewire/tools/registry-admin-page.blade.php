<div class="space-y-6">
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">MCP Server Registry</h1>
            <p class="mt-1 text-sm text-gray-600">
                Platform-curated catalog of approved MCP servers. Any team can install entries marked
                <span class="font-medium">Active</span>; installed Tools keep working even if the registry
                entry is later removed.
            </p>
        </div>
        <button wire:click="openCreate" type="button"
            class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700">
            Add server
        </button>
    </div>

    @if (session('success'))
        <div class="rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    @if ($showCreate)
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">New registry entry</h2>
            <form wire:submit="save" class="mt-4 space-y-4">
                <x-form-input wire:model="name" label="Name" required />
                <x-form-textarea wire:model="description" label="Description" hint="Why a team should install this" />
                <x-form-select wire:model.live="transport" label="Transport" :options="['mcp_stdio' => 'MCP Server (stdio)', 'mcp_http' => 'MCP Server (HTTP)']" />

                @if ($transport === 'mcp_http')
                    <x-form-input wire:model="connectionUrl" label="HTTP URL" required />
                @else
                    <x-form-input wire:model="connectionCommand" label="Command" hint="e.g. npx -y @some/mcp-server" required />
                @endif

                <x-form-select wire:model="trustLevel" label="Trust level"
                    :options="collect($trustLevels)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray()" />

                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('showCreate', false)" type="button"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit"
                        class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Create
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Transport</th>
                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Trust</th>
                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $entry->name }}</div>
                            @if ($entry->description)
                                <div class="text-xs text-gray-500">{{ $entry->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $entry->transport }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $entry->trust_level?->label() }}</td>
                        <td class="px-4 py-3">
                            @if ($entry->is_active)
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Active</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="toggleActive('{{ $entry->id }}')" type="button"
                                class="text-sm text-gray-600 hover:text-gray-900">
                                {{ $entry->is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button wire:click="install('{{ $entry->id }}')" type="button"
                                class="ml-3 text-sm font-medium text-primary-600 hover:text-primary-800"
                                @disabled(! $entry->is_active)>
                                Install to my team
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                            No registry entries yet. Click <span class="font-medium">Add server</span> to seed one.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
