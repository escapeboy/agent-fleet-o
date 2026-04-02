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
                    <i class="fa-solid fa-check text-base"></i>
                @elseif($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Failed)
                    <i class="fa-solid fa-xmark text-base"></i>
                @elseif($run->status === \App\Domain\Project\Enums\ProjectRunStatus::Running)
                    <i class="fa-solid fa-spinner fa-spin text-base"></i>
                @else
                    <i class="fa-regular fa-clock text-base"></i>
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
