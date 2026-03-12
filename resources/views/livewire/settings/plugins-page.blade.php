<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Plugins</h1>
        <p class="mt-1 text-sm text-gray-500">
            Manage installed plugins. Changes take effect after restarting the application.
        </p>
    </div>

    @if($total === 0)
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
            <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.4.959.4v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z"/>
            </svg>
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
