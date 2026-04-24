<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">External Agents</h1>
            <p class="text-sm text-gray-600 mt-1">
                Remote agents registered via the <span class="font-mono text-xs">Agent Chat Protocol</span>. Callable from workflows, crews, and the assistant.
            </p>
        </div>
        <button type="button" wire:click="openRegister"
                class="inline-flex items-center px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500">
            Register remote agent
        </button>
    </div>

    @if (session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="p-4 flex gap-3 border-b border-gray-200">
            <x-form-input wire:model.live.debounce.300ms="search" placeholder="Search by name" compact />
            <x-form-select wire:model.live="statusFilter" compact>
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="paused">Paused</option>
                <option value="unreachable">Unreachable</option>
                <option value="disabled">Disabled</option>
            </x-form-select>
        </div>

        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Endpoint</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last call</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($agents as $agent)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm">
                            <a href="{{ route('external-agents.show', ['externalAgent' => $agent->id]) }}"
                               class="text-primary-600 hover:text-primary-700 font-medium">
                                {{ $agent->name }}
                            </a>
                            <div class="text-xs text-gray-500 font-mono mt-1">{{ $agent->slug }}</div>
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-600 font-mono truncate max-w-xs">{{ $agent->endpoint_url }}</td>
                        <td class="px-6 py-4 text-sm">
                            <span @class([
                                'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                                'bg-green-100 text-green-800' => $agent->status->value === 'active',
                                'bg-yellow-100 text-yellow-800' => $agent->status->value === 'paused',
                                'bg-red-100 text-red-800' => in_array($agent->status->value, ['unreachable', 'disabled']),
                            ])>
                                {{ $agent->status->value }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-500">
                            {{ $agent->last_call_at?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                            No external agents registered yet. Click "Register remote agent" to add one.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="p-4 border-t border-gray-200">
            {{ $agents->links() }}
        </div>
    </div>

    @if ($showRegisterModal)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50 p-4"
             wire:click.self="$set('showRegisterModal', false)">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Register a remote agent</h2>

                <form wire:submit="register" class="space-y-4">
                    <x-form-input label="Name" wire:model="newName" required />
                    <x-form-input label="Endpoint URL" wire:model="newEndpointUrl"
                                  hint="Base URL of the remote agent. Manifest is auto-fetched from {endpoint}/manifest."
                                  required />
                    <x-form-textarea label="Description" wire:model="newDescription" />

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" wire:click="$set('showRegisterModal', false)"
                                class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700">
                            Register
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
