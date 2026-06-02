<div class="mx-auto max-w-3xl space-y-6">
    @if(session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $policy->name }}</h2>
            <p class="mt-1 text-sm text-gray-500">
                {{ $policy->agent_id ? 'Agent-specific' : 'Team default' }} ·
                current v{{ $current?->version ?? 1 }} ·
                {{ $policy->status->label() }}
            </p>
        </div>
        <button wire:click="toggleEnabled" type="button"
            class="rounded-lg px-4 py-2 text-sm font-medium {{ $policy->enabled ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $policy->enabled ? 'Enabled' : 'Disabled' }}
        </button>
    </div>

    {{-- Current rules --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Current rules (v{{ $current?->version ?? 1 }})</h3>
        <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700">{{ json_encode($current?->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>

    {{-- Version history --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Version history</h3>
        <div class="divide-y divide-gray-100">
            @foreach($versions as $version)
                <div class="flex items-center justify-between py-3">
                    <div>
                        <span class="text-sm font-medium text-gray-900">v{{ $version->version }}</span>
                        @if($version->id === $policy->current_version_id)
                            <span class="ml-2 inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700">current</span>
                        @endif
                        @if($version->rolled_back_from_version_id)
                            <span class="ml-2 text-xs text-gray-400">(rollback)</span>
                        @endif
                        <p class="mt-0.5 text-xs text-gray-400">{{ $version->notes ?? '—' }} · {{ $version->created_at?->diffForHumans() }}</p>
                    </div>
                    @if($version->id !== $policy->current_version_id)
                        <button wire:click="rollback('{{ $version->id }}')" type="button"
                            class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Roll back to v{{ $version->version }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <a href="{{ route('policies.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; All policies</a>
</div>
