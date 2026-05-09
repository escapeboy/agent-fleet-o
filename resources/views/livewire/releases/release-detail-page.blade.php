<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $release->version }}</span>
                <span class="inline-flex items-center rounded-full bg-{{ $release->status->color() }}-100 px-2 py-0.5 text-xs font-medium text-{{ $release->status->color() }}-700">
                    {{ $release->status->label() }}
                </span>
                @if($release->isPublished() && $release->share_token)
                    <a href="{{ route('releases.share', $release->share_token) }}" target="_blank"
                        class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-800">
                        <i class="fa-solid fa-link"></i>Public share link
                    </a>
                @endif
            </div>
            @if($release->notes)
                <p class="mt-2 max-w-2xl text-sm text-gray-600">{{ $release->notes }}</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($release->isDraft())
                <button wire:click="publish"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                    <i class="fa-solid fa-rocket mr-1"></i>Publish
                </button>
            @endif
            @unless($release->isArchived())
                <button wire:click="archive" wire:confirm="Archive this release?"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Archive
                </button>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">
                Attached artifacts ({{ $artifacts->count() }})
            </h3>
            @forelse($artifacts as $artifact)
                <div class="flex items-center justify-between border-b border-gray-100 py-3 last:border-0">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ $artifact->name }}</div>
                        <div class="text-xs text-gray-500">{{ $artifact->type }} · v{{ $artifact->pivot->artifact_version }}</div>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-400">No artifacts attached yet.</p>
            @endforelse
        </div>

        @unless($release->isArchived())
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Attach artifact</h3>
                <x-form-select wire:model="attachArtifactId" label="Pick an artifact"
                    :error="$errors->first('attachArtifactId')">
                    <option value="">— Select —</option>
                    @foreach($availableArtifacts as $artifact)
                        <option value="{{ $artifact->id }}">{{ $artifact->name }} ({{ $artifact->type }})</option>
                    @endforeach
                </x-form-select>
                <button wire:click="attach"
                    class="mt-3 w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Attach
                </button>
            </div>
        @endunless
    </div>
</div>
