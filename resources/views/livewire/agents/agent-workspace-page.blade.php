@php
    $tabs = [
        'draft' => ['label' => 'Draft', 'desc' => 'Configure'],
        'test' => ['label' => 'Test', 'desc' => 'Run live'],
        'deploy' => ['label' => 'Deploy', 'desc' => 'Ship it'],
        'script' => ['label' => 'Script', 'desc' => 'What the LLM sees'],
    ];
@endphp

<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <a href="{{ route('agents.index') }}" class="hover:text-gray-700">Agents</a>
                <span>/</span>
                <a href="{{ route('agents.show', $agent) }}" class="hover:text-gray-700">{{ $agent->name }}</a>
                <span>/</span>
                <span class="text-gray-900">Workspace</span>
            </div>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ $agent->name }} workspace</h1>
            @if ($agent->role)
                <p class="mt-1 text-sm text-gray-600">{{ $agent->role }}</p>
            @endif
        </div>
    </div>

    <div class="border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @foreach ($tabs as $key => $meta)
                <button
                    wire:click="setTab('{{ $key }}')"
                    type="button"
                    @class([
                        'border-b-2 px-1 pb-3 pt-2 text-sm font-medium transition',
                        'border-primary-600 text-primary-700' => $activeTab === $key,
                        'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $activeTab !== $key,
                    ])
                >
                    {{ $meta['label'] }}
                    <span class="ml-1 text-xs font-normal text-gray-400">— {{ $meta['desc'] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    @if ($activeTab === 'draft')
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Draft</h2>
            <p class="mt-1 text-sm text-gray-600">
                Edit role, goal, backstory, skills, tools, and execution settings in the full agent editor.
            </p>
            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded border border-gray-100 bg-gray-50 p-3">
                    <div class="text-xs uppercase text-gray-500">Role</div>
                    <div class="mt-1 truncate font-medium text-gray-900">{{ $agent->role ?: '—' }}</div>
                </div>
                <div class="rounded border border-gray-100 bg-gray-50 p-3">
                    <div class="text-xs uppercase text-gray-500">Provider · model</div>
                    <div class="mt-1 truncate font-medium text-gray-900">{{ $agent->provider ?: '—' }} · {{ $agent->model ?: '—' }}</div>
                </div>
                <div class="rounded border border-gray-100 bg-gray-50 p-3">
                    <div class="text-xs uppercase text-gray-500">Status</div>
                    <div class="mt-1 truncate font-medium text-gray-900">{{ $agent->status?->value ?? '—' }}</div>
                </div>
            </div>
            <a href="{{ route('agents.show', $agent) }}"
                class="mt-5 inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Open full editor
            </a>
        </div>
    @elseif ($activeTab === 'test')
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Test</h2>
            <p class="mt-1 text-sm text-gray-600">
                Use the embedded assistant in the layout to talk to this agent. For full sandbox runs with
                tools attached, head to the agent execution panel.
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('agents.show', $agent) }}?tab=test"
                    class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Open sandbox
                </a>
                <a href="{{ route('agents.show', $agent) }}?tab=executions"
                    class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    View past executions
                </a>
            </div>
        </div>
    @elseif ($activeTab === 'deploy')
        <div class="space-y-4">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Deploy</h2>
                <p class="mt-1 text-sm text-gray-600">Ship this agent to where it will do work.</p>
                <ul class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <li class="rounded border border-gray-100 bg-gray-50 p-3">
                        <div class="font-medium text-gray-900">Publish to marketplace</div>
                        <p class="mt-1 text-xs text-gray-600">Share this agent with other teams.</p>
                        <a href="{{ route('app.marketplace.publish') }}?subject_type=agent&subject_id={{ $agent->id }}"
                            class="mt-2 inline-block text-sm font-medium text-primary-600 hover:text-primary-800">
                            Open publish form →
                        </a>
                    </li>
                    <li class="rounded border border-gray-100 bg-gray-50 p-3">
                        <div class="font-medium text-gray-900">Attach to a workflow</div>
                        <p class="mt-1 text-xs text-gray-600">Wire this agent into a multi-step DAG.</p>
                        <a href="{{ route('workflows.index') }}"
                            class="mt-2 inline-block text-sm font-medium text-primary-600 hover:text-primary-800">
                            Browse workflows →
                        </a>
                    </li>
                    <li class="rounded border border-gray-100 bg-gray-50 p-3">
                        <div class="font-medium text-gray-900">Schedule with a project</div>
                        <p class="mt-1 text-xs text-gray-600">Run on a continuous or one-shot schedule.</p>
                        <a href="{{ route('projects.create') }}"
                            class="mt-2 inline-block text-sm font-medium text-primary-600 hover:text-primary-800">
                            New project →
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    @elseif ($activeTab === 'script')
        @php($script = $this->script)
        <div class="space-y-4">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Script preview</h2>
                <p class="mt-1 text-sm text-gray-600">
                    The combined system prompt and resource bindings the LLM receives when this agent runs.
                    Read-only — edit in the Draft tab.
                </p>

                <div class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Provider · model</div>
                        <div class="mt-1 font-mono text-sm text-gray-900">{{ $script['provider'] ?: '—' }} · {{ $script['model'] ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Skills attached ({{ count($script['skills']) }})</div>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            @forelse ($script['skills'] as $s)
                                <li class="font-mono text-gray-800">· {{ $s['name'] }}@if ($s['type']) <span class="text-gray-400">[{{ $s['type'] }}]</span>@endif</li>
                            @empty
                                <li class="text-gray-500">(none)</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Tools attached ({{ count($script['tools']) }})</div>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            @forelse ($script['tools'] as $t)
                                <li class="font-mono text-gray-800">· {{ $t['name'] }} <span class="text-gray-400">[{{ $t['type'] }}]</span></li>
                            @empty
                                <li class="text-gray-500">(none)</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="text-xs uppercase tracking-wide text-gray-500">System prompt</div>
                    <pre class="mt-1 max-h-96 overflow-auto rounded-md border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-gray-800">{{ $script['system_prompt'] }}</pre>
                </div>
            </div>
        </div>
    @endif
</div>
