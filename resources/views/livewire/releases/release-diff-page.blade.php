<div>
    <div class="mb-4">
        <a href="{{ route('releases.show', $release) }}" class="text-xs text-primary-600 hover:text-primary-800">← Back to release</a>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-700">Left (older)</label>
            <select wire:model.live="leftArtifactId"
                class="w-full rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500">
                <option value="">— Select —</option>
                @foreach($attached as $a)
                    <option value="{{ $a->id }}">{{ $a->name }} (v{{ $a->pivot->artifact_version }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-700">Right (newer)</label>
            <select wire:model.live="rightArtifactId"
                class="w-full rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500">
                <option value="">— Select —</option>
                @foreach($attached as $a)
                    <option value="{{ $a->id }}">{{ $a->name }} (v{{ $a->pivot->artifact_version }})</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white" data-test="release-diff">
        <div class="grid grid-cols-12 border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-semibold text-gray-600">
            <div class="col-span-1 text-right">left</div>
            <div class="col-span-1 text-right">right</div>
            <div class="col-span-10">content</div>
        </div>
        @forelse($segments as $segment)
            <div class="grid grid-cols-12 border-b border-gray-50 px-4 py-1 text-xs font-mono last:border-0
                {{ match($segment['type']) {
                    'add' => 'bg-green-50',
                    'remove' => 'bg-red-50',
                    'unsupported' => 'bg-amber-50',
                    default => 'bg-white',
                } }}"
                data-test-segment="{{ $segment['type'] }}">
                <div class="col-span-1 text-right text-gray-400">{{ $segment['left'] ?? '' }}</div>
                <div class="col-span-1 text-right text-gray-400">{{ $segment['right'] ?? '' }}</div>
                <div class="col-span-10 whitespace-pre-wrap break-all
                    {{ match($segment['type']) {
                        'add' => 'text-green-700',
                        'remove' => 'text-red-700',
                        'unsupported' => 'italic text-amber-700',
                        default => 'text-gray-700',
                    } }}">
                    @if($segment['type'] === 'add')+ @elseif($segment['type'] === 'remove')- @else  @endif{{ $segment['text'] }}
                </div>
            </div>
        @empty
            <div class="px-4 py-12 text-center text-sm text-gray-400">
                Select artifacts above to see the diff.
            </div>
        @endforelse
    </div>
</div>
