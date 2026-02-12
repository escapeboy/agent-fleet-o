<div>
    @forelse($recentRuns as $run)
        <div class="relative mb-4 flex gap-4">
            {{-- Timeline line --}}
            @if(! $loop->last)
                <div class="absolute left-4 top-8 h-full w-0.5 bg-gray-200"></div>
            @endif

            {{-- Icon --}}
            <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                {{ $run->status === \App\Domain\Project\Enums\ProjectRunStatus::Completed ? 'bg-green-100 text-green-600' :
                   ($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Failed ? 'bg-red-100 text-red-600' :
                   ($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Running ? 'bg-blue-100 text-blue-600' :
                   'bg-gray-100 text-gray-400')) }}">
                @if($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Completed)
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                @elseif($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Failed)
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                @elseif($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Running)
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                @else
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                @endif
            </div>

            {{-- Content --}}
            <div class="flex-1 rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-900">Run #{{ $run->run_number }}</span>
                        <x-status-badge :status="$run->status->value" />
                    </div>
                    <span class="text-xs text-gray-400">{{ $run->created_at->diffForHumans() }}</span>
                </div>

                <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                    @if($run->duration())
                        <span>Duration: {{ $run->durationForHumans() }}</span>
                    @endif
                    @if($run->cost_credits)
                        <span>Cost: {{ $run->cost_credits }} credits</span>
                    @endif
                    @if($run->experiment_id)
                        <a href="{{ route('experiments.show', $run->experiment_id) }}" class="text-primary-600 hover:underline">
                            View run details
                        </a>
                    @endif
                </div>

                @if($run->error_message)
                    <div class="mt-2 rounded-lg bg-red-50 p-2 text-xs text-red-700">
                        {{ $run->error_message }}
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center text-sm text-gray-400">
            No runs yet. {{ $isActive ? 'Waiting for the next scheduled run...' : 'Start the project to trigger the first run.' }}
        </div>
    @endforelse
</div>
