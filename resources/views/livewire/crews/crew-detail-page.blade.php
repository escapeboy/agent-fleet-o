<div>
    {{-- Flash --}}
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $crew->status === \App\Domain\Crew\Enums\CrewStatus::Active ? 'bg-green-100 text-green-700' : ($crew->status === \App\Domain\Crew\Enums\CrewStatus::Draft ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700') }}">
                    {{ $crew->status->label() }}
                </span>
                <span class="text-sm text-gray-500">{{ $crew->process_type->label() }} process</span>
            </div>
            @if($crew->description)
                <p class="mt-1 text-sm text-gray-500">{{ $crew->description }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($crew->status === \App\Domain\Crew\Enums\CrewStatus::Active)
                <a href="{{ route('crews.execute', $crew) }}"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Execute
                </a>
            @endif
            @if($crew->status === \App\Domain\Crew\Enums\CrewStatus::Draft)
                <button wire:click="activate" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Activate
                </button>
            @endif
            <button wire:click="toggleStatus" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $crew->status === \App\Domain\Crew\Enums\CrewStatus::Active ? 'Archive' : 'Activate' }}
            </button>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @foreach(['overview' => 'Overview', 'executions' => 'Executions', 'settings' => 'Settings'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="border-b-2 pb-3 text-sm font-medium transition
                        {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'executions')
                        <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ $executions->count() }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Overview Tab --}}
    @if($activeTab === 'overview')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Coordinator --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Coordinator</h3>
                @if($crew->coordinator)
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div>
                            <a href="{{ route('agents.show', $crew->coordinator) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $crew->coordinator->name }}</a>
                            <p class="text-xs text-gray-500">{{ $crew->coordinator->role ?? 'No role' }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ $crew->coordinator->provider }}/{{ $crew->coordinator->model }}</p>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400">No coordinator assigned</p>
                @endif
            </div>

            {{-- QA Agent --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">QA Agent</h3>
                @if($crew->qaAgent)
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 text-purple-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <a href="{{ route('agents.show', $crew->qaAgent) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $crew->qaAgent->name }}</a>
                            <p class="text-xs text-gray-500">{{ $crew->qaAgent->role ?? 'No role' }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ $crew->qaAgent->provider }}/{{ $crew->qaAgent->model }}</p>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400">No QA agent assigned</p>
                @endif
            </div>

            {{-- Workers --}}
            <div class="col-span-full rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">
                    Workers ({{ $members->count() }})
                </h3>
                @if($members->isNotEmpty())
                    <div class="space-y-3">
                        @foreach($members as $member)
                            <div class="flex items-center gap-3 rounded-lg border border-gray-100 p-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-xs font-medium text-gray-600">
                                    {{ $member->sort_order + 1 }}
                                </div>
                                <div class="flex-1">
                                    <a href="{{ route('agents.show', $member->agent) }}" class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                        {{ $member->agent->name }}
                                    </a>
                                    <span class="ml-2 text-xs text-gray-500">{{ $member->agent->role ?? '' }}</span>
                                </div>
                                <span class="text-xs text-gray-400">{{ $member->agent->provider }}/{{ $member->agent->model }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400">No workers — coordinator will execute all tasks itself.</p>
                @endif
            </div>
        </div>
    @endif

    {{-- Executions Tab --}}
    @if($activeTab === 'executions')
        <div class="space-y-4">
            @forelse($executions as $execution)
                <div class="rounded-xl border border-gray-200 bg-white p-6" x-data="{ expanded: false }">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ match($execution->status->value) {
                                        'completed' => 'bg-green-100 text-green-700',
                                        'failed', 'terminated' => 'bg-red-100 text-red-700',
                                        'executing', 'planning' => 'bg-blue-100 text-blue-700',
                                        'paused' => 'bg-yellow-100 text-yellow-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    } }}">
                                    {{ $execution->status->label() }}
                                </span>
                                <span class="text-sm text-gray-500">{{ $execution->created_at->diffForHumans() }}</span>
                                @if($execution->quality_score)
                                    <span class="text-sm font-medium {{ $execution->quality_score >= 0.7 ? 'text-green-600' : 'text-yellow-600' }}">
                                        QA: {{ number_format($execution->quality_score * 100) }}%
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-700">{{ Str::limit($execution->goal, 120) }}</p>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-400">
                            @if($execution->duration_ms)
                                <span>{{ number_format($execution->duration_ms / 1000, 1) }}s</span>
                            @endif
                            @if($execution->total_cost_credits)
                                <span>{{ $execution->total_cost_credits }} credits</span>
                            @endif
                            <span>{{ $execution->completedTaskCount() }}/{{ $execution->totalTaskCount() }} tasks</span>
                            <button @click="expanded = !expanded" class="text-primary-600 hover:text-primary-800">
                                <span x-text="expanded ? 'Hide' : 'Show'"></span> details
                            </button>
                        </div>
                    </div>

                    {{-- Expanded: task list --}}
                    <div x-show="expanded" x-cloak class="mt-4 border-t border-gray-100 pt-4">
                        @if($execution->status->isActive())
                            <livewire:crews.crew-execution-panel :execution-id="$execution->id" wire:key="exec-{{ $execution->id }}" wire:poll.2s />
                        @else
                            @foreach($execution->taskExecutions as $task)
                                <div class="flex items-center gap-3 border-b border-gray-50 py-2 last:border-0">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        bg-{{ $task->status->color() }}-100 text-{{ $task->status->color() }}-700">
                                        {{ $task->status->label() }}
                                    </span>
                                    <span class="flex-1 text-sm text-gray-700">{{ $task->title }}</span>
                                    <span class="text-xs text-gray-400">
                                        {{ $task->agent?->name ?? 'Unassigned' }}
                                        @if($task->duration_ms) &middot; {{ number_format($task->duration_ms / 1000, 1) }}s @endif
                                        @if($task->qa_score) &middot; QA: {{ number_format($task->qa_score * 100) }}% @endif
                                    </span>
                                </div>
                            @endforeach
                        @endif

                        @if($execution->error_message)
                            <div class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                                {{ $execution->error_message }}
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-400">
                    No executions yet.
                    @if($crew->status === \App\Domain\Crew\Enums\CrewStatus::Active)
                        <a href="{{ route('crews.execute', $crew) }}" class="text-primary-600 hover:underline">Launch one now</a>.
                    @endif
                </div>
            @endforelse
        </div>
    @endif

    {{-- Settings Tab --}}
    @if($activeTab === 'settings')
        @if($editing)
            <form wire:submit="save" class="space-y-6">
                <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                    <x-form-input wire:model="editName" label="Name" :error="$errors->first('editName')" />
                    <x-form-textarea wire:model="editDescription" label="Description" />

                    <x-form-select wire:model="editProcessType" label="Process Type" :error="$errors->first('editProcessType')">
                        @foreach($processTypes as $pt)
                            <option value="{{ $pt->value }}">{{ $pt->label() }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editCoordinatorId" label="Coordinator" :error="$errors->first('editCoordinatorId')">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editQaId" label="QA Agent" :error="$errors->first('editQaId')">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </x-form-select>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Workers</label>
                        @foreach($agents as $agent)
                            @if($agent->id !== $editCoordinatorId && $agent->id !== $editQaId)
                                <label class="flex items-center gap-2 py-1">
                                    <input type="checkbox" wire:click="toggleWorker('{{ $agent->id }}')"
                                        {{ in_array($agent->id, $editWorkerIds) ? 'checked' : '' }}
                                        class="h-4 w-4 rounded border-gray-300 text-primary-600">
                                    <span class="text-sm text-gray-700">{{ $agent->name }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <x-form-input wire:model="editMaxIterations" type="number" label="Max Retries" min="1" max="10" :error="$errors->first('editMaxIterations')" />
                        <x-form-input wire:model="editQualityThreshold" type="number" label="Quality Threshold" min="0" max="1" step="0.05" :error="$errors->first('editQualityThreshold')" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Save Changes</button>
                </div>
            </form>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500">Configuration</h3>
                    <button wire:click="startEdit" class="text-sm font-medium text-primary-600 hover:text-primary-800">Edit</button>
                </div>

                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Process Type</dt>
                        <dd class="font-medium text-gray-900">{{ $crew->process_type->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="font-medium text-gray-900">{{ $crew->status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Max Task Retries</dt>
                        <dd class="font-medium text-gray-900">{{ $crew->max_task_iterations }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Quality Threshold</dt>
                        <dd class="font-medium text-gray-900">{{ number_format($crew->quality_threshold * 100) }}%</dd>
                    </div>
                </dl>

                <div class="mt-6 border-t border-gray-200 pt-4">
                    <button wire:click="deleteCrew"
                        wire:confirm="Are you sure you want to delete this crew?"
                        class="text-sm font-medium text-red-600 hover:text-red-800">
                        Delete Crew
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>
