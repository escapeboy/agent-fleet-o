<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Plugins</h1>
        <p class="mt-1 text-sm text-gray-500">
            Manage installed plugins. Changes take effect after restarting the application.
        </p>
    </div>

    @if($total === 0)
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
            <i class="fa-solid fa-puzzle-piece mx-auto text-3xl text-gray-400"></i>
            <h3 class="mt-3 text-sm font-medium text-gray-900">No plugins installed</h3>
            <p class="mt-1 text-sm text-gray-500">
                Install plugins via Composer and they will appear here automatically.
            </p>
            <div class="mt-4">
                <code class="rounded bg-gray-100 px-3 py-1.5 text-xs font-mono text-gray-700">
                    composer require vendor/fleet-plugin-name
                </code>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Plugin</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Version</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Health</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Installed</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $row['plugin']->getName() }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['plugin']->getId() }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                {{ $row['version'] }}
                            </td>
                            <td class="px-6 py-4">
                                @if($row['enabled'])
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                        Enabled
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                        Disabled
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if($row['health'] !== null)
                                    @if($row['health']->healthy)
                                        <span class="text-xs text-green-600">{{ $row['health']->status }}</span>
                                    @else
                                        <span class="text-xs text-red-600">{{ $row['health']->status }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $row['installed_at'] ? $row['installed_at']->diffForHumans() : '—' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button
                                    wire:click="togglePlugin('{{ $row['plugin']->getId() }}')"
                                    wire:confirm="{{ $row['enabled'] ? 'Disable this plugin?' : 'Enable this plugin?' }}"
                                    class="text-sm font-medium {{ $row['enabled'] ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}"
                                >
                                    {{ $row['enabled'] ? 'Disable' : 'Enable' }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
