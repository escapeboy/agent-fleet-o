<div wire:poll.15s class="rounded-lg border border-gray-200 bg-white p-4">
    <h3 class="mb-3 text-sm font-medium text-gray-900">Orchestration Tree</h3>

    {{-- Root experiment --}}
    <div class="space-y-1">
        @include('livewire.experiments.partials.orchestration-node', ['node' => $root, 'depth' => 0, 'currentId' => $experiment->id])
    </div>

    {{-- Orchestration config summary --}}
    @if($experiment->orchestration_config)
        <div class="mt-3 border-t border-gray-100 pt-3">
            <p class="text-xs font-medium text-gray-500">Orchestration Config</p>
            <div class="mt-1 flex flex-wrap gap-2">
                @if($experiment->orchestration_config['failure_policy'] ?? null)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                        Policy: {{ str_replace('_', ' ', $experiment->orchestration_config['failure_policy']) }}
                    </span>
                @endif
                @if($experiment->orchestration_config['max_children'] ?? null)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                        Max children: {{ $experiment->orchestration_config['max_children'] }}
                    </span>
                @endif
                @if($experiment->orchestration_config['max_nesting_depth'] ?? null)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                        Max depth: {{ $experiment->orchestration_config['max_nesting_depth'] }}
                    </span>
                @endif
            </div>
        </div>
    @endif
</div>
