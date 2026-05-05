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
                    <i class="fa-solid fa-spinner fa-spin text-base"></i>
                    Running…
                @else
                    <i class="fa-solid fa-play text-base"></i>
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
                        <i class="fa-solid fa-bolt text-sm"></i>
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
                                <i class="fa-solid fa-spinner fa-spin text-base"></i>
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
                                <i class="fa-solid fa-thumbs-up text-lg"></i>
                            </button>
                            <button
                                wire:click="annotate('{{ $modelId }}', 'bad')"
                                title="Mark as bad"
                                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                            >
                                <i class="fa-solid fa-thumbs-down text-lg"></i>
                            </button>
                            <span class="text-xs text-gray-400">Rate this output</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(!$isRunning)
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
            <i class="fa-solid fa-table-columns mx-auto mb-3 text-3xl text-gray-300"></i>
            <p class="text-sm text-gray-500">Enter a test input and click <strong>Run Comparison</strong> to compare model outputs.</p>
        </div>
    @endif
</div>
