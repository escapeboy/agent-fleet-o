<div wire:poll.10s>
    @if($events->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No execution events yet.</p>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-4 py-3">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span class="text-sm font-medium text-gray-700">Execution Log</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $events->count() }} events</span>
                </div>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($events as $event)
                    <div>
                        <button wire:click="toggleEvent('{{ $event['id'] }}')"
                            class="flex w-full items-center justify-between px-4 py-2 text-left transition hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                {{-- Color dot --}}
                                @php
                                    $dotColor = match($event['color']) {
                                        'green' => 'bg-green-500',
                                        'red' => 'bg-red-500',
                                        'blue' => 'bg-blue-500',
                                        default => 'bg-gray-400',
                                    };
                                    $typeLabel = match($event['type']) {
                                        'transition' => 'STATE',
                                        'stage' => 'STAGE',
                                        'step' => 'STEP',
                                        'llm_call' => 'LLM',
                                        default => '?',
                                    };
                                    $typeBg = match($event['type']) {
                                        'transition' => 'bg-purple-100 text-purple-700',
                                        'stage' => 'bg-sky-100 text-sky-700',
                                        'step' => 'bg-amber-100 text-amber-700',
                                        'llm_call' => 'bg-emerald-100 text-emerald-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                @endphp
                                <span class="h-2 w-2 rounded-full {{ $dotColor }}"></span>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $typeBg }}">{{ $typeLabel }}</span>
                                <span class="text-xs text-gray-900">{{ $event['summary'] }}</span>
                            </div>

                            <div class="flex items-center gap-3">
                                @if($event['detail'])
                                    <span class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($event['detail'], 60) }}</span>
                                @endif
                                <span class="text-[10px] text-gray-400">{{ $event['time']->format('H:i:s') }}</span>
                                <svg class="h-3.5 w-3.5 text-gray-400 transition {{ $expandedEventId === $event['id'] ? 'rotate-180' : '' }}"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                        </button>

                        @if($expandedEventId === $event['id'])
                            <div class="border-t border-gray-100 bg-gray-50 px-4 py-3">
                                @if($event['detail'])
                                    <p class="text-xs text-gray-600">{{ $event['detail'] }}</p>
                                @endif

                                @if($event['metadata'])
                                    <div class="mt-2">
                                        <pre class="max-h-48 overflow-auto rounded bg-gray-900 p-2 text-xs text-green-400">{{ json_encode($event['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                @endif

                                @if($event['color'] === 'red' && $event['type'] === 'step')
                                    <div class="mt-2 rounded bg-red-50 p-2">
                                        <p class="text-xs font-medium text-red-700">This step failed. Check the error above for details.</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
