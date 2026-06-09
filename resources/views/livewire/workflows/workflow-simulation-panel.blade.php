<div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="shrink-0 text-gray-500 hover:text-gray-700">
                <i class="fa-solid fa-arrow-left text-lg"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-gray-900">Simulate: {{ $workflow->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Dry-run the workflow graph. No agents run, no LLM calls, no cost.
                </p>
            </div>
        </div>

        <button type="button" wire:click="simulate" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
            <i class="fa-solid fa-play" wire:loading.remove wire:target="simulate"></i>
            <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="simulate"></i>
            <span>Run simulation</span>
        </button>
    </div>

    @if($error)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $error }}
        </div>
    @endif

    @if($hasRun && ! $error)
        <div class="mb-4 flex items-center gap-3">
            <span class="text-sm font-medium text-gray-700">Predicted outcome:</span>
            @php($statusColor = match($terminationStatus) {
                'completed' => 'green',
                'loop_limit' => 'amber',
                default => 'red',
            })
            <span class="inline-flex items-center rounded-full bg-{{ $statusColor }}-100 px-2.5 py-0.5 text-xs font-medium text-{{ $statusColor }}-800">
                {{ str($terminationStatus)->replace('_', ' ')->title() }}
            </span>
            <span class="text-xs text-gray-400">{{ count($executedPath) }} nodes visited</span>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <ol class="divide-y divide-gray-100">
                @foreach($executedPath as $i => $step)
                    <li class="flex items-center gap-3 px-4 py-3 {{ $step['id'] === $terminationNodeId ? 'bg-gray-50' : '' }}">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">
                            {{ $i + 1 }}
                        </span>
                        <span class="flex-1 text-sm font-medium text-gray-900">{{ $step['label'] }}</span>
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $step['type'] }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @elseif(! $hasRun)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
            <i class="fa-solid fa-diagram-project mb-3 text-3xl text-gray-300"></i>
            <p class="text-sm text-gray-500">Run a simulation to preview the predicted execution path.</p>
        </div>
    @endif
</div>
