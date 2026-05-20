<div>
    {{-- Kind filter pills --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $pills = [
                null => 'All',
                'transition' => 'Transitions',
                'ai_run' => 'AI runs',
                'approval' => 'Approvals',
                'sandbox_file' => 'Sandbox files',
            ];
        @endphp
        @foreach($pills as $value => $label)
            <button type="button" wire:click="$set('kindFilter', {{ $value === null ? 'null' : "'".$value."'" }})"
                class="rounded-full border px-3 py-1 text-xs font-medium transition
                {{ $kindFilter === $value
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Timeline --}}
    @forelse($entries as $entry)
        @php
            $tone = match($entry->actor->value) {
                'human' => ['dot' => 'bg-primary-100 text-primary-600', 'tag' => 'bg-primary-50 text-primary-700'],
                'agent' => ['dot' => 'bg-indigo-100 text-indigo-600', 'tag' => 'bg-indigo-50 text-indigo-700'],
                default => ['dot' => 'bg-gray-100 text-gray-500', 'tag' => 'bg-gray-100 text-gray-600'],
            };
        @endphp
        <div class="flex gap-3">
            {{-- Rail --}}
            <div class="flex flex-col items-center">
                <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $tone['dot'] }}">
                    <i class="{{ $entry->icon }} text-sm"></i>
                </div>
                @if(! $loop->last)
                    <div class="w-px flex-1 bg-gray-200"></div>
                @endif
            </div>
            {{-- Card --}}
            <div class="mb-3 flex-1 rounded-xl border border-gray-200 bg-white p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900">{{ $entry->title }}</div>
                        @if($entry->summary)
                            <div class="mt-0.5 truncate text-xs text-gray-500">{{ $entry->summary }}</div>
                        @endif
                    </div>
                    <div class="shrink-0 text-right">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide {{ $tone['tag'] }}">
                            {{ $entry->actor->label() }}
                        </span>
                        <div class="mt-1 text-[11px] text-gray-400" title="{{ $entry->occurredAt->toDayDateTimeString() }}">
                            {{ $entry->occurredAt->diffForHumans() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center text-sm text-gray-400">
            No activity recorded yet for this experiment.
        </div>
    @endforelse
</div>
