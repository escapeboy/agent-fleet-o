<div>
    {{-- Global flag banner --}}
    @unless($globalEnabled)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-lg text-amber-600"></i>
                <div>
                    <h4 class="text-sm font-semibold text-amber-900">Policy governance is globally disabled</h4>
                    <p class="mt-0.5 text-sm text-amber-700">
                        Policies are scored and recorded, but routing is unchanged until
                        <code class="rounded bg-amber-100 px-1">AGENT_POLICIES_ENABLED=true</code>. This is the master safety switch.
                    </p>
                </div>
            </div>
        </div>
    @endunless

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search policies..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>
        <a href="{{ route('policies.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Policy
        </a>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Scope</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Risk ceiling</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Version</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Enabled</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($policies as $policy)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <a href="{{ route('policies.show', $policy) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                {{ $policy->name }}
                            </a>
                        </td>
                        <td class="px-6 py-4">
                            @if($policy->agent_id)
                                <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                    {{ $policy->agent?->name ?? 'Agent' }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Team default</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $policy->currentVersion?->rules['risk_ceiling'] ?? '--' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">v{{ $policy->currentVersion?->version ?? 1 }}</td>
                        <td class="px-6 py-4">
                            <button wire:click="toggleEnabled('{{ $policy->id }}')" type="button"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $policy->enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' }}">
                                {{ $policy->enabled ? 'Enabled' : 'Disabled' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">
                            No agent policies yet. Create one to govern autonomous action routing.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $policies->links() }}</div>
</div>
