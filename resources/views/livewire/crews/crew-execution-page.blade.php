<div class="mx-auto max-w-3xl">
    {{-- Crew Summary --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Crew: {{ $crew->name }}</h3>

        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Process:</span>
                <span class="ml-1 font-medium text-gray-900">{{ $crew->process_type->label() }}</span>
            </div>
            <div>
                <span class="text-gray-500">Quality Threshold:</span>
                <span class="ml-1 font-medium text-gray-900">{{ number_format($crew->quality_threshold * 100) }}%</span>
            </div>
        </div>

        {{-- Team roster --}}
        <div class="mt-4 border-t border-gray-100 pt-4">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @if($coordinator)
                    <div class="flex items-center gap-2 rounded-lg bg-blue-50 p-2">
                        <span class="inline-flex items-center rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700">Coordinator</span>
                        <span class="text-sm text-gray-700">{{ $coordinator->name }}</span>
                    </div>
                @endif

                @if($qaAgent)
                    <div class="flex items-center gap-2 rounded-lg bg-purple-50 p-2">
                        <span class="inline-flex items-center rounded bg-purple-100 px-1.5 py-0.5 text-xs font-medium text-purple-700">QA</span>
                        <span class="text-sm text-gray-700">{{ $qaAgent->name }}</span>
                    </div>
                @endif

                @foreach($members as $member)
                    <div class="flex items-center gap-2 rounded-lg bg-gray-50 p-2">
                        <span class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">Worker</span>
                        <span class="text-sm text-gray-700">{{ $member->agent->name }}</span>
                        @if($member->agent->role)
                            <span class="text-xs text-gray-400">{{ $member->agent->role }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($members->isEmpty())
                <p class="mt-2 text-xs text-gray-400">No workers — coordinator will execute all tasks itself.</p>
            @endif
        </div>
    </div>

    {{-- Goal Input --}}
    <form wire:submit="execute" class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Goal</h3>
            <p class="mb-3 text-xs text-gray-500">Describe what you want this crew to accomplish. The coordinator will decompose it into tasks.</p>

            <x-form-textarea
                wire:model="goal"
                placeholder="e.g. Research the top 5 competitors in the SaaS market and produce a competitive analysis report with recommendations..."
                rows="5"
                :error="$errors->first('goal')" />
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('crews.show', $crew) }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Execute Crew
            </button>
        </div>
    </form>
</div>
