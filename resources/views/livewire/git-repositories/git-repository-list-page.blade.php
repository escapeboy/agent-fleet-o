<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search repositories..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="modeFilter">
            <option value="">All Modes</option>
            @foreach($modes as $mode)
                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="providerFilter">
            <option value="">All Providers</option>
            @foreach($providers as $provider)
                <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('git-repositories.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Connect Repository
        </a>
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
        @if($repositories->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <i class="fa-solid fa-code mb-4 text-4xl text-gray-300"></i>
                <p class="text-gray-500">No git repositories connected yet.</p>
                <a href="{{ route('git-repositories.create') }}" class="mt-3 text-sm font-medium text-primary-600 hover:text-primary-700">
                    Connect your first repository →
                </a>
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Repository</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Provider</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Mode</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Ping</th>
                        <th class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach($repositories as $repo)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <a href="{{ route('git-repositories.show', $repo) }}" class="font-medium text-gray-900 hover:text-primary-600">
                                    {{ $repo->name }}
                                </a>
                                <p class="mt-0.5 truncate text-xs text-gray-500 max-w-xs">{{ $repo->url }}</p>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">{{ $repo->provider->label() }}</td>
                            <td class="px-6 py-4">
                                @php
                                    $modeColors = ['api_only' => 'blue', 'sandbox' => 'purple', 'bridge' => 'orange'];
                                    $color = $modeColors[$repo->mode->value] ?? 'gray';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $color }}-100 text-{{ $color }}-800">
                                    {{ $repo->mode->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $statusColors = ['active' => 'green', 'disabled' => 'gray', 'error' => 'red'];
                                    $sColor = $statusColors[$repo->status->value] ?? 'gray';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $sColor }}-100 text-{{ $sColor }}-800">
                                    {{ $repo->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $repo->last_ping_at ? $repo->last_ping_at->diffForHumans() : '—' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('git-repositories.show', $repo) }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                                    View →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-gray-200 px-6 py-3">
                {{ $repositories->links() }}
            </div>
        @endif
    </div>
</div>
