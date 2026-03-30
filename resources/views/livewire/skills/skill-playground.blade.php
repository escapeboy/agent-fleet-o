<div
    @if($isRunning)
        wire:poll.2s="pollResults"
    @endif
>
    {{-- Messages --}}
    @if($errorMessage)
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errorMessage }}</div>
    @endif
    @if($successMessage)
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ $successMessage }}</div>
    @endif

    {{-- Controls --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            {{-- Version Selector --}}
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700">Skill Version</label>
                <select
                    wire:change="$dispatch('skill-version-changed', {versionId: $event.target.value})"
                    class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:ring-primary-500"
                >
                    @foreach($versions as $v)
                        <option value="{{ $v->id }}" @selected($v->id === $versionId)>
                            v{{ $v->version }} ({{ $v->created_at->format('M j') }})
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Model Checkboxes --}}
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-gray-700">Compare Models</label>
                <div class="flex flex-wrap gap-2">
                    @foreach(['anthropic/claude-sonnet-4-5' => 'Claude Sonnet', 'anthropic/claude-haiku-4-5' => 'Claude Haiku', 'openai/gpt-4o' => 'GPT-4o', 'openai/gpt-4o-mini' => 'GPT-4o Mini', 'google/gemini-2.5-flash' => 'Gemini Flash'] as $modelId => $modelLabel)
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs {{ in_array($modelId, $selectedModels) ? 'border-primary-300 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:border-gray-300' }}">
                            <input
                                type="checkbox"
                                wire:model.live="selectedModels"
                                value="{{ $modelId }}"
                                class="h-3 w-3 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                            >
                            {{ $modelLabel }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Test Input --}}
        <div class="mb-4">
            <label class="mb-1 block text-xs font-medium text-gray-700">Test Input</label>
            <textarea
                wire:model="testInput"
                rows="4"
                placeholder="Type your test input here. Use {{variable}} syntax if your prompt template has placeholders."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-primary-500 focus:ring-primary-500"
            ></textarea>
            @error('testInput')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Run Button --}}
        <div class="flex items-center justify-between">
            <button
                wire:click="run"
                wire:loading.attr="disabled"
                @if(empty($selectedModels) || $isRunning) disabled @endif
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                @if($isRunning)
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Running…
                @else
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Run Comparison
                @endif
            </button>

            {{-- Annotation Counter + Generate Improvement --}}
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-500">
                    {{ $annotationCount }} annotation{{ $annotationCount !== 1 ? 's' : '' }}
                    @if($annotationCount < 3)
                        — need {{ 3 - $annotationCount }} more to improve
                    @endif
                </span>

                @if($annotationCount >= 3)
                    <button
                        wire:click="generateImprovement"
                        wire:loading.attr="disabled"
                        wire:target="generateImprovement"
                        class="inline-flex items-center gap-2 rounded-lg border border-primary-300 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-100 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <span wire:loading.remove wire:target="generateImprovement">Generate Improved Version</span>
                        <span wire:loading wire:target="generateImprovement">Generating…</span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Results Grid --}}
    @if(!empty($results))
        <div class="grid gap-4" style="grid-template-columns: repeat({{ count($selectedModels) }}, 1fr)">
            @foreach($selectedModels as $modelId)
                @php $result = $results[$modelId] ?? null; @endphp
                <div class="rounded-xl border border-gray-200 bg-white">
                    {{-- Model Header --}}
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <span class="truncate text-sm font-medium text-gray-900">{{ $modelId }}</span>
                        @if($result && $result['done'] && $result['error'] === null)
                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                <span>{{ $result['latency_ms'] ? number_format($result['latency_ms']).'ms' : '' }}</span>
                                <span>{{ $result['cost'] }} cr</span>
                            </div>
                        @endif
                    </div>

                    {{-- Output Body --}}
                    <div class="min-h-32 p-4">
                        @if(!$result || !$result['done'])
                            <div class="flex items-center gap-2 text-sm text-gray-400">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Waiting…
                            </div>
                        @elseif($result['error'] !== null)
                            <p class="text-sm text-red-600">{{ $result['error'] }}</p>
                        @else
                            <pre class="whitespace-pre-wrap font-mono text-xs leading-relaxed text-gray-800">{{ $result['output'] }}</pre>
                        @endif
                    </div>

                    {{-- Annotation Buttons --}}
                    @if($result && $result['done'] && $result['error'] === null)
                        <div class="flex items-center gap-2 border-t border-gray-100 px-4 py-2">
                            <button
                                wire:click="annotate('{{ $modelId }}', 'good')"
                                title="Mark as good"
                                class="rounded p-1 text-gray-400 hover:bg-green-50 hover:text-green-600"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/>
                                </svg>
                            </button>
                            <button
                                wire:click="annotate('{{ $modelId }}', 'bad')"
                                title="Mark as bad"
                                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5"/>
                                </svg>
                            </button>
                            <span class="text-xs text-gray-400">Rate this output</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(!$isRunning)
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
            <svg class="mx-auto mb-3 h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
            </svg>
            <p class="text-sm text-gray-500">Enter a test input and click <strong>Run Comparison</strong> to compare model outputs.</p>
        </div>
    @endif
</div>
