<div>
    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search signals by sender, payload...">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>
        <x-form-select wire:model.live="sourceTypeFilter">
            <option value="">All Sources</option>
            @foreach($sourceTypes as $type)
                <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
            @endforeach
        </x-form-select>
    </div>

    <div class="grid grid-cols-1 gap-6 {{ $selectedSignal ? 'lg:grid-cols-2' : '' }}">
        {{-- Signal List --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            @if($signals->isEmpty())
                <div class="flex flex-col items-center justify-center py-16">
                    <svg class="mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <p class="mb-1 text-sm font-medium text-gray-900">No signals yet</p>
                    <p class="text-sm text-gray-500">Signals arrive from connectors (email, RSS, webhooks, etc.)</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">From</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Summary</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tags</th>
                                <th wire:click="sort('created_at')"
                                    class="cursor-pointer px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                                    Received
                                    @if($sortBy === 'created_at')
                                        <span class="ml-0.5">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($signals as $signal)
                                <tr wire:click="selectSignal('{{ $signal->id }}')"
                                    class="cursor-pointer hover:bg-gray-50 {{ $selectedSignalId === $signal->id ? 'bg-primary-50' : '' }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @php
                                            $sourceColors = [
                                                'email' => 'bg-blue-100 text-blue-700',
                                                'rss' => 'bg-orange-100 text-orange-700',
                                                'webhook' => 'bg-purple-100 text-purple-700',
                                                'api_polling' => 'bg-teal-100 text-teal-700',
                                                'telegram' => 'bg-sky-100 text-sky-700',
                                                'github_issues' => 'bg-gray-100 text-gray-700',
                                                'sentry' => 'bg-red-100 text-red-700',
                                                'calendar' => 'bg-green-100 text-green-700',
                                            ];
                                            $color = $sourceColors[$signal->source_type] ?? 'bg-gray-100 text-gray-600';
                                        @endphp
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $color }}">
                                            {{ ucfirst(str_replace('_', ' ', $signal->source_type)) }}
                                        </span>
                                    </td>
                                    <td class="max-w-[160px] truncate px-4 py-3 text-sm text-gray-700">
                                        {{ $signal->source_identifier }}
                                    </td>
                                    <td class="max-w-[250px] truncate px-4 py-3 text-sm text-gray-600">
                                        {{ $signal->payload['subject'] ?? $signal->payload['title'] ?? Str::limit($signal->payload['body'] ?? $signal->payload['content'] ?? '—', 80) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($signal->tags)
                                            @foreach(array_slice($signal->tags, 0, 3) as $tag)
                                                <span class="mr-1 inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                                            @endforeach
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500">
                                        {{ $signal->created_at->diffForHumans() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $signals->links() }}
                </div>
            @endif
        </div>

        {{-- Signal Detail Panel --}}
        @if($selectedSignal)
            <div class="space-y-4">
                {{-- Signal Info --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Signal Detail</h3>
                        <button wire:click="selectSignal(null)" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Source Type</dt>
                            <dd class="font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $selectedSignal->source_type)) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">From</dt>
                            <dd class="font-medium text-gray-900">{{ $selectedSignal->source_identifier }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Received</dt>
                            <dd class="text-gray-700">{{ $selectedSignal->created_at->format('Y-m-d H:i:s') }}</dd>
                        </div>
                        @if($selectedSignal->score)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Score</dt>
                                <dd class="font-medium text-gray-900">{{ number_format($selectedSignal->score, 2) }}</dd>
                            </div>
                        @endif
                        @if($selectedSignal->duplicate_count > 0)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Duplicates</dt>
                                <dd class="text-gray-700">{{ $selectedSignal->duplicate_count }}</dd>
                            </div>
                        @endif
                        @if($selectedSignal->tags)
                            <div>
                                <dt class="mb-1 text-gray-500">Tags</dt>
                                <dd class="flex flex-wrap gap-1">
                                    @foreach($selectedSignal->tags as $tag)
                                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                {{-- Payload --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">Payload</h3>
                    @if(isset($selectedSignal->payload['subject']))
                        <div class="mb-2">
                            <span class="text-xs font-medium text-gray-500">Subject:</span>
                            <p class="text-sm text-gray-900">{{ $selectedSignal->payload['subject'] }}</p>
                        </div>
                    @endif
                    @if(isset($selectedSignal->payload['body']))
                        <div class="mb-2">
                            <span class="text-xs font-medium text-gray-500">Body:</span>
                            <p class="max-h-48 overflow-y-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-sm text-gray-700">{{ Str::limit($selectedSignal->payload['body'], 2000) }}</p>
                        </div>
                    @elseif(isset($selectedSignal->payload['content']))
                        <div class="mb-2">
                            <span class="text-xs font-medium text-gray-500">Content:</span>
                            <p class="max-h-48 overflow-y-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-sm text-gray-700">{{ Str::limit($selectedSignal->payload['content'], 2000) }}</p>
                        </div>
                    @else
                        <pre class="max-h-48 overflow-y-auto rounded bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($selectedSignal->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    @endif
                </div>

                {{-- Triggered Runs --}}
                @if($triggerRuns->isNotEmpty())
                    <div class="rounded-xl border border-gray-200 bg-white p-5">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">Triggered Runs</h3>
                        <div class="space-y-2">
                            @foreach($triggerRuns as $run)
                                <a href="{{ $run->experiment_id ? route('experiments.show', $run->experiment_id) : route('projects.show', $run->project_id) }}"
                                    class="flex items-center justify-between rounded-lg border border-gray-100 p-3 hover:bg-gray-50">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ $run->project?->title ?? 'Project Run' }}</p>
                                        <p class="text-xs text-gray-500">{{ $run->created_at->diffForHumans() }}</p>
                                    </div>
                                    @php $statusVal = $run->status instanceof \BackedEnum ? $run->status->value : $run->status; @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium
                                        {{ $statusVal === 'completed' ? 'bg-green-100 text-green-800' :
                                           ($statusVal === 'running' ? 'bg-blue-100 text-blue-800' :
                                           ($statusVal === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600')) }}">
                                        {{ ucfirst($statusVal) }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Entities --}}
                @if($selectedSignal->entities->isNotEmpty())
                    <div class="rounded-xl border border-gray-200 bg-white p-5">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">Extracted Entities</h3>
                        <div class="space-y-1">
                            @foreach($selectedSignal->entities as $entity)
                                <div class="flex items-center justify-between rounded px-2 py-1.5 hover:bg-gray-50">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ $entity->type }}</span>
                                        <span class="text-sm text-gray-900">{{ $entity->name }}</span>
                                    </div>
                                    @if($entity->pivot->confidence)
                                        <span class="text-xs text-gray-400">{{ number_format($entity->pivot->confidence * 100) }}%</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
