<div class="space-y-3">
    @forelse($artifacts as $artifact)
        <div class="rounded-lg border border-gray-200 bg-white">
            <button wire:click="toggleArtifact('{{ $artifact->id }}')"
                class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-gray-50">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ $artifact->name }}</p>
                    <p class="text-xs text-gray-500">{{ $artifact->type }} &middot; v{{ $artifact->current_version }}</p>
                </div>
                <svg class="h-4 w-4 text-gray-400 transition {{ $expandedArtifactId === $artifact->id ? 'rotate-180' : '' }}"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            @if($expandedArtifactId === $artifact->id)
                <div class="border-t border-gray-200">
                    @foreach($artifact->versions->sortByDesc('version') as $version)
                        <div class="border-b border-gray-100 px-4 py-3 last:border-b-0">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-xs font-medium text-gray-600">Version {{ $version->version }}</span>
                                <span class="text-xs text-gray-400">{{ $version->created_at->diffForHumans() }}</span>
                            </div>
                            <pre class="max-h-64 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700">{{ is_string($version->content) ? $version->content : json_encode($version->content, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No artifacts built yet.</p>
        </div>
    @endforelse
</div>
