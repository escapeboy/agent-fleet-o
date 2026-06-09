<div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('experiments.show', $this->experiment) }}"
               class="text-sm text-primary-600 hover:text-primary-700">&larr; Back to experiment</a>
            <h1 class="mt-1 text-xl font-semibold text-gray-900">Checkpoints</h1>
            <p class="text-sm text-gray-500">{{ $this->experiment->title }}</p>
        </div>

        @if($this->checkpoints->isNotEmpty())
            <button type="button"
                    wire:click="$set('showResumeConfirm', true)"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                Resume from latest checkpoint
            </button>
        @endif
    </div>

    @if(session('message'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('message') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
        Resume re-triggers execution from the <strong>most recent</strong> checkpoint, preserving each step's
        saved state. The list below is informational — there is no per-checkpoint rollback.
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Step</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Version</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Worker</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Idempotency key</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last heartbeat</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($this->checkpoints as $step)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">#{{ $step->order }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$step->status" /></td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $step->checkpoint_version ?? '-' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $step->worker_id ?? '-' }}</td>
                            <td class="px-4 py-3 max-w-xs truncate font-mono text-xs text-gray-500">{{ $step->idempotency_key ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $step->last_heartbeat_at?->diffForHumans() ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $step->updated_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">
                                No checkpoints recorded for this experiment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($showResumeConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-gray-900">Resume from checkpoint?</h2>
                <p class="mt-2 text-sm text-gray-600">
                    This re-triggers execution from the most recent checkpoint. Saved step state is preserved.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button"
                            wire:click="$set('showResumeConfirm', false)"
                            class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button"
                            wire:click="resumeFromCheckpoint"
                            class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Resume
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
