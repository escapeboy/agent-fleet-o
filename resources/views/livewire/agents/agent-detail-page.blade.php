<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-gray-900">{{ $agent->name }}</h2>
                <x-status-badge :status="$agent->status->value" />
            </div>
            @if($agent->role)
                <p class="mt-1 text-sm font-medium text-gray-600">{{ $agent->role }}</p>
            @endif
            @if($agent->goal)
                <p class="mt-0.5 text-sm text-gray-500">{{ $agent->goal }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">{{ $agent->provider }}/{{ $agent->model }}</span>
            <button wire:click="toggleStatus"
                class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'Disable' : 'Enable' }}
            </button>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-4 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ $skills->count() }}</div>
            <div class="text-sm text-gray-500">Skills Assigned</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ $executions->count() }}</div>
            <div class="text-sm text-gray-500">Recent Executions</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ number_format($agent->budget_spent_credits) }}</div>
            <div class="text-sm text-gray-500">Credits Spent</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ $agent->budget_cap_credits ? number_format($agent->budgetRemainingCredits()) : 'Unlimited' }}</div>
            <div class="text-sm text-gray-500">Budget Remaining</div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            @foreach(['overview' => 'Overview', 'skills' => 'Skills', 'executions' => 'Executions'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'overview')
        <div class="space-y-6">
            @if($agent->backstory)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-2 text-sm font-semibold text-gray-700">Backstory</h3>
                    <p class="text-sm text-gray-600 whitespace-pre-wrap">{{ $agent->backstory }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-2 text-sm font-semibold text-gray-700">Capabilities</h3>
                    <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->capabilities ?? [], JSON_PRETTY_PRINT) }}</pre>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-2 text-sm font-semibold text-gray-700">Constraints</h3>
                    <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->constraints ?? [], JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </div>

    @elseif($activeTab === 'skills')
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skill</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($skills as $skill)
                        <tr>
                            <td class="px-6 py-4">
                                <a href="{{ route('skills.show', $skill) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $skill->name }}</a>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $skill->type->label() }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $skill->pivot->priority }}</td>
                            <td class="px-6 py-4"><x-status-badge :status="$skill->status->value" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">No skills assigned</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @elseif($activeTab === 'executions')
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skills</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($executions as $exec)
                        <tr>
                            <td class="px-6 py-4"><x-status-badge :status="$exec->status" /></td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ count($exec->skills_executed ?? []) }} skills</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->duration_ms ? number_format($exec->duration_ms) . 'ms' : '-' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->cost_credits }} credits</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">No executions yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
