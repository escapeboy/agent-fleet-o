<div>
    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-start justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-gray-900">{{ $listing->name }}</h2>
                @if($listing->is_official)
                    <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        Official
                    </span>
                @endif
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ match($listing->type) {
                        'skill' => 'bg-purple-100 text-purple-800',
                        'workflow' => 'bg-green-100 text-green-800',
                        'bundle' => 'bg-orange-100 text-orange-800',
                        'email_theme' => 'bg-pink-100 text-pink-800',
                        'email_template' => 'bg-rose-100 text-rose-800',
                        default => 'bg-blue-100 text-blue-800',
                    } }}">
                    {{ str_replace('_', ' ', ucfirst($listing->type)) }}
                </span>
                <span class="text-sm text-gray-400">v{{ $listing->version }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $listing->description }}</p>
            <p class="mt-1 text-xs text-gray-400">Published by {{ $listing->is_official ? 'FleetQ' : ($listing->team?->name ?? 'Unknown') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('app.marketplace.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                Back
            </a>
            @auth
                @if($isInstalled)
                    <span class="rounded-lg border border-green-300 bg-green-50 px-4 py-1.5 text-sm font-medium text-green-700">
                        Installed
                    </span>
                @else
                    <button wire:click="install"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="install">Install</span>
                        <span wire:loading wire:target="install">Installing...</span>
                    </button>
                @endif
            @endauth
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ number_format($listing->install_count) }}</div>
            <div class="text-sm text-gray-500">Installs</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center gap-1">
                <span class="text-2xl font-bold text-gray-900">{{ $listing->review_count > 0 ? number_format($listing->avg_rating, 1) : '—' }}</span>
                @if($listing->review_count > 0)
                    <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                @endif
            </div>
            <div class="text-sm text-gray-500">Rating ({{ $listing->review_count }} reviews)</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            @if($listing->run_count > 0)
                <div class="text-2xl font-bold text-gray-900">{{ number_format($listing->run_count) }}</div>
                <div class="text-sm text-gray-500">
                    Total Runs
                    @if($listing->run_count > 0)
                        <span class="text-green-600">({{ round(($listing->success_count / $listing->run_count) * 100, 1) }}% success)</span>
                    @endif
                </div>
            @else
                <div class="text-2xl font-bold text-gray-400">—</div>
                <div class="text-sm text-gray-500">Total Runs</div>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            @if($listing->isPaid())
                <div class="text-2xl font-bold text-amber-600">{{ number_format($listing->price_per_run_credits, 0) }}</div>
                <div class="text-sm text-gray-500">credits / run</div>
            @else
                <div class="text-2xl font-bold text-green-600">Free</div>
                <div class="text-sm text-gray-500">Price per run</div>
            @endif
        </div>
    </div>

    {{-- Tags --}}
    @if($listing->category || !empty($listing->tags))
        <div class="mb-4 flex flex-wrap gap-1">
            @if($listing->category)
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $listing->category }}</span>
            @endif
            @foreach(($listing->tags ?? []) as $tag)
                <span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    {{-- Tabs --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            @php
                $tabs = ['overview' => 'Overview', 'configuration' => 'Configuration', 'reviews' => 'Reviews'];
                if ($isPublisher) {
                    $tabs['analytics'] = 'Analytics';
                }
            @endphp
            @foreach($tabs as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'reviews')
                        <span class="ml-1 text-xs text-gray-400">({{ $listing->review_count }})</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'overview')
        @php $snapshot = $listing->configuration_snapshot ?? []; @endphp
        <div class="space-y-4">

            {{-- Description (always shown) --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-2 text-sm font-semibold text-gray-700">Description</h3>
                <p class="text-sm text-gray-600">{{ $listing->description }}</p>
            </div>

            {{-- Skill: system prompt --}}
            @if($listing->type === 'skill' && !empty($snapshot['system_prompt']))
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">System Prompt</h3>
                    <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-4 font-mono text-xs text-gray-700 leading-relaxed">{{ $snapshot['system_prompt'] }}</pre>
                </div>
            @endif

            {{-- Agent: role, goal, backstory --}}
            @if($listing->type === 'agent')
                @if(!empty($snapshot['role']) || !empty($snapshot['goal']) || !empty($snapshot['backstory']))
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <h3 class="mb-4 text-sm font-semibold text-gray-700">Agent Persona</h3>
                        <dl class="space-y-4">
                            @if(!empty($snapshot['role']))
                                <div>
                                    <dt class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400">Role</dt>
                                    <dd class="text-sm text-gray-700">{{ $snapshot['role'] }}</dd>
                                </div>
                            @endif
                            @if(!empty($snapshot['goal']))
                                <div>
                                    <dt class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400">Goal</dt>
                                    <dd class="text-sm text-gray-700">{{ $snapshot['goal'] }}</dd>
                                </div>
                            @endif
                            @if(!empty($snapshot['backstory']))
                                <div>
                                    <dt class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400">Backstory</dt>
                                    <dd class="text-sm text-gray-600 italic">{{ $snapshot['backstory'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @endif
                @if(!empty($snapshot['capabilities']) || !empty($snapshot['constraints']))
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <h3 class="mb-4 text-sm font-semibold text-gray-700">Capabilities & Constraints</h3>
                        @if(!empty($snapshot['capabilities']))
                            <div class="mb-4">
                                <h4 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Capabilities</h4>
                                <ul class="space-y-1">
                                    @foreach($snapshot['capabilities'] as $cap)
                                        <li class="flex items-start gap-2 text-sm text-gray-700">
                                            <svg class="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            {{ $cap }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if(!empty($snapshot['constraints']))
                            <div>
                                <h4 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Constraints</h4>
                                <ul class="space-y-1">
                                    @foreach($snapshot['constraints'] as $con)
                                        <li class="flex items-start gap-2 text-sm text-gray-700">
                                            <svg class="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                            {{ $con }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            {{-- Workflow: description + node list --}}
            @if($listing->type === 'workflow')
                @if(!empty($snapshot['description']))
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Workflow Description</h3>
                        <p class="text-sm text-gray-600">{{ $snapshot['description'] }}</p>
                    </div>
                @endif
                @if(!empty($snapshot['nodes']))
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Steps</h3>
                        <ol class="space-y-2">
                            @foreach(collect($snapshot['nodes'])->sortBy('order')->values() as $i => $node)
                                @if(!in_array($node['type'], ['start', 'end']))
                                    <li class="flex items-start gap-3">
                                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">{{ $loop->iteration }}</span>
                                        <div class="min-w-0 flex-1">
                                            <span class="text-sm text-gray-700">{{ $node['label'] }}</span>
                                            @if(!empty($node['config']['description']))
                                                <p class="mt-0.5 text-xs text-gray-400">{{ $node['config']['description'] }}</p>
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $node['type'] === 'conditional' ? 'bg-yellow-100 text-yellow-700' :
                                               ($node['type'] === 'human_task' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700') }}">
                                            {{ ucfirst(str_replace('_', ' ', $node['type'])) }}
                                        </span>
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    </div>
                @endif
            @endif

            {{-- Email theme: color preview in overview --}}
            @if($listing->type === 'email_theme' && !empty($snapshot['primary_color']))
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Color Palette</h3>
                    <div class="flex flex-wrap gap-3">
                        @foreach([
                            'Primary' => $snapshot['primary_color'] ?? null,
                            'Background' => $snapshot['background_color'] ?? null,
                            'Canvas' => $snapshot['canvas_color'] ?? null,
                            'Text' => $snapshot['text_color'] ?? null,
                        ] as $label => $color)
                            @if($color)
                                <div class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2">
                                    <div class="h-5 w-5 rounded-full border border-gray-200 shadow-sm" style="background-color: {{ $color }};"></div>
                                    <span class="text-xs text-gray-500">{{ $label }}</span>
                                    <span class="font-mono text-xs text-gray-700">{{ $color }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    @if(!empty($snapshot['font_name']))
                        <p class="mt-3 text-xs text-gray-400">Font: <span class="font-medium text-gray-600">{{ $snapshot['font_name'] }}</span></p>
                    @endif
                </div>
            @endif

            {{-- Email template: live preview in overview --}}
            @if($listing->type === 'email_template' && !empty($snapshot['html_cache']))
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Email Preview</h3>
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50" style="height: 500px;">
                        <iframe srcdoc="{{ e($snapshot['html_cache']) }}"
                            class="h-full w-full"
                            sandbox="allow-same-origin"
                            title="Email preview"></iframe>
                    </div>
                    @if(!empty($snapshot['subject']))
                        <p class="mt-2 text-xs text-gray-500">Subject: <span class="font-medium text-gray-700">{{ $snapshot['subject'] }}</span></p>
                    @endif
                </div>
            @endif

            {{-- README (optional additional docs) --}}
            @if($listing->readme)
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">README</h3>
                    <div class="prose max-w-none text-sm text-gray-700">
                        {!! nl2br(e($listing->readme)) !!}
                    </div>
                </div>
            @endif

        </div>

    @elseif($activeTab === 'configuration')
        <div class="space-y-4">
            @php $snapshot = $listing->configuration_snapshot ?? []; @endphp

            @if($listing->type === 'skill')
                @if(!empty($snapshot['input_schema']['properties'] ?? []))
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Input Schema</h3>
                        <div class="space-y-2">
                            @foreach($snapshot['input_schema']['properties'] as $name => $def)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <span class="font-mono text-sm">{{ $name }}</span>
                                    <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($snapshot['output_schema']['properties'] ?? []))
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Output Schema</h3>
                        <div class="space-y-2">
                            @foreach($snapshot['output_schema']['properties'] as $name => $def)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <span class="font-mono text-sm">{{ $name }}</span>
                                    <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @elseif($listing->type === 'agent')
                {{-- Agent config --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Agent Configuration</h3>
                    <dl class="space-y-3">
                        @if(!empty($snapshot['role']))
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Role</dt>
                                <dd class="text-sm text-gray-700">{{ $snapshot['role'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($snapshot['goal']))
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Goal</dt>
                                <dd class="text-sm text-gray-700">{{ $snapshot['goal'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($snapshot['provider']))
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Provider / Model</dt>
                                <dd class="text-sm text-gray-700">{{ $snapshot['provider'] }} / {{ $snapshot['model'] ?? 'default' }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

            @elseif($listing->type === 'workflow')
                {{-- Workflow config --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Workflow Overview</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Nodes</dt>
                            <dd class="text-sm text-gray-700">{{ $snapshot['node_count'] ?? count($snapshot['nodes'] ?? []) }} nodes ({{ $snapshot['agent_node_count'] ?? 0 }} agent nodes)</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Connections</dt>
                            <dd class="text-sm text-gray-700">{{ count($snapshot['edges'] ?? []) }} edges</dd>
                        </div>
                        @if(!empty($snapshot['max_loop_iterations']))
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Max Loop Iterations</dt>
                                <dd class="text-sm text-gray-700">{{ $snapshot['max_loop_iterations'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($snapshot['estimated_cost_credits']))
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Estimated Cost</dt>
                                <dd class="text-sm text-gray-700">{{ number_format($snapshot['estimated_cost_credits']) }} credits</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                @if(!empty($snapshot['nodes']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Nodes</h3>
                        <div class="space-y-2">
                            @foreach($snapshot['nodes'] as $node)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $node['type'] === 'start' ? 'bg-green-100 text-green-800' :
                                               ($node['type'] === 'end' ? 'bg-red-100 text-red-800' :
                                               ($node['type'] === 'conditional' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')) }}">
                                            {{ ucfirst($node['type']) }}
                                        </span>
                                        <span class="text-sm text-gray-700">{{ $node['label'] }}</span>
                                    </div>
                                    @if(!empty($node['agent_name']) || !empty($node['skill_name']))
                                        <span class="text-xs text-gray-500">
                                            {{ $node['agent_name'] ?? '' }}{{ !empty($node['agent_name']) && !empty($node['skill_name']) ? ' / ' : '' }}{{ $node['skill_name'] ?? '' }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            @elseif($listing->type === 'email_theme')
                {{-- Email theme color/typography preview --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Theme Configuration</h3>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach([
                            'Primary Color' => $snapshot['primary_color'] ?? null,
                            'Background' => $snapshot['background_color'] ?? null,
                            'Canvas' => $snapshot['canvas_color'] ?? null,
                            'Text Color' => $snapshot['text_color'] ?? null,
                            'Heading Color' => $snapshot['heading_color'] ?? null,
                            'Divider Color' => $snapshot['divider_color'] ?? null,
                        ] as $label => $color)
                            @if($color)
                                <div class="flex items-center gap-2">
                                    <div class="h-5 w-5 flex-shrink-0 rounded-full border border-gray-200" style="background-color: {{ $color }};"></div>
                                    <div>
                                        <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                        <dd class="font-mono text-xs text-gray-700">{{ $color }}</dd>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    @if(!empty($snapshot['font_name']))
                        <div class="mt-3 border-t border-gray-100 pt-3">
                            <dt class="text-xs text-gray-400">Font</dt>
                            <dd class="text-sm text-gray-700">{{ $snapshot['font_name'] }} ({{ $snapshot['body_font_size'] ?? 16 }}px / {{ $snapshot['heading_font_size'] ?? 24 }}px heading)</dd>
                        </div>
                    @endif
                    @if(!empty($snapshot['company_name']) || !empty($snapshot['footer_text']))
                        <div class="mt-3 border-t border-gray-100 pt-3">
                            @if(!empty($snapshot['company_name']))
                                <dt class="text-xs text-gray-400">Company</dt>
                                <dd class="text-sm text-gray-700">{{ $snapshot['company_name'] }}</dd>
                            @endif
                            @if(!empty($snapshot['footer_text']))
                                <div class="mt-2">
                                    <dt class="text-xs text-gray-400">Footer Text</dt>
                                    <dd class="text-sm text-gray-500">{{ Str::limit($snapshot['footer_text'], 120) }}</dd>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

            @elseif($listing->type === 'email_template')
                {{-- Email template: live preview iframe --}}
                @if(!empty($snapshot['html_cache']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Email Preview</h3>
                        <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50" style="height: 500px;">
                            <iframe srcdoc="{{ e($snapshot['html_cache']) }}"
                                class="h-full w-full"
                                sandbox="allow-same-origin"
                                title="Email preview"></iframe>
                        </div>
                        @if(!empty($snapshot['subject']))
                            <p class="mt-2 text-xs text-gray-500">Subject: <span class="font-medium text-gray-700">{{ $snapshot['subject'] }}</span></p>
                        @endif
                    </div>
                @else
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <p class="text-sm text-gray-400">No preview available — template has not been rendered yet.</p>
                    </div>
                @endif

            @elseif($listing->type === 'bundle')
                {{-- Bundle contents --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Bundle Contents</h3>
                    <p class="mb-3 text-xs text-gray-500">This bundle contains {{ count($snapshot['items'] ?? []) }} items. Installing this bundle will add all items to your workspace.</p>
                    <div class="space-y-2">
                        @foreach($snapshot['items'] ?? [] as $bundleItem)
                            <div class="flex items-center gap-3 rounded border border-gray-100 px-3 py-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $bundleItem['type'] === 'skill' ? 'bg-purple-100 text-purple-800' :
                                       ($bundleItem['type'] === 'workflow' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') }}">
                                    {{ ucfirst($bundleItem['type']) }}
                                </span>
                                <span class="text-sm text-gray-700">{{ $bundleItem['name'] }}</span>
                                @if(!empty($bundleItem['description']))
                                    <span class="ml-auto text-xs text-gray-400">{{ Str::limit($bundleItem['description'], 60) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

    @elseif($activeTab === 'analytics' && $isPublisher && $publisherStats)
        <div class="space-y-6">
            {{-- Summary KPIs --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="text-xs text-gray-400">Total Runs</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($publisherStats['run_count']) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="text-xs text-gray-400">Success Rate</p>
                    <p class="mt-1 text-2xl font-bold {{ ($publisherStats['success_rate'] ?? 100) >= 90 ? 'text-green-600' : 'text-yellow-600' }}">
                        {{ $publisherStats['success_rate'] !== null ? $publisherStats['success_rate'].'%' : '—' }}
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="text-xs text-gray-400">Avg Cost / Run</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">
                        {{ $publisherStats['avg_cost_credits'] !== null ? number_format($publisherStats['avg_cost_credits'], 1) : '—' }}
                    </p>
                    @if($publisherStats['avg_cost_credits'] !== null)<p class="text-xs text-gray-400">credits</p>@endif
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="text-xs text-gray-400">Last 30d Runs</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($publisherStats['last_30d_runs']) }}</p>
                    @if($publisherStats['last_30d_failures'] > 0)
                        <p class="text-xs text-red-500">{{ $publisherStats['last_30d_failures'] }} failures</p>
                    @endif
                </div>
            </div>

            {{-- Monthly trend spark chart --}}
            @if(!empty($publisherStats['usage_trend']))
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 text-sm font-medium text-gray-500">Monthly Usage Trend</h3>
                    @php
                        $maxRuns = max(array_column($publisherStats['usage_trend'], 'runs')) ?: 1;
                    @endphp
                    <div class="flex h-16 items-end gap-1">
                        @foreach($publisherStats['usage_trend'] as $month)
                            @php $barH = max(($month['runs'] / $maxRuns) * 100, $month['runs'] > 0 ? 4 : 0); @endphp
                            <div class="group relative flex-1" title="{{ $month['period'] }}: {{ number_format($month['runs']) }} runs">
                                <div class="w-full rounded-sm bg-primary-200 hover:bg-primary-400 transition-all" style="height: {{ $barH }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex justify-between text-xs text-gray-400">
                        <span>{{ $publisherStats['usage_trend'][0]['period'] ?? '' }}</span>
                        <span>{{ end($publisherStats['usage_trend'])['period'] ?? '' }}</span>
                    </div>
                </div>
            @endif

            {{-- Monetization --}}
            @if($publisherStats['monetization_enabled'])
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                    <h3 class="mb-2 text-sm font-medium text-amber-800">Monetization Active</h3>
                    <p class="text-sm text-amber-700">
                        Price: <strong>{{ number_format($publisherStats['price_per_run'], 2) }} credits / run</strong>
                        (platform takes 20%, you receive {{ number_format($publisherStats['price_per_run'] * 0.8, 2) }} credits per run)
                    </p>
                </div>
            @endif
        </div>

    @elseif($activeTab === 'reviews')
        <div class="space-y-6">
            {{-- Write Review --}}
            @auth
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Write a Review</h3>
                    <div class="mb-3 flex items-center gap-1">
                        @for($i = 1; $i <= 5; $i++)
                            <button wire:click="$set('reviewRating', {{ $i }})"
                                class="text-2xl {{ $i <= $reviewRating ? 'text-yellow-400' : 'text-gray-300' }} hover:text-yellow-400">
                                &#9733;
                            </button>
                        @endfor
                    </div>
                    <x-form-textarea wire:model="reviewComment" rows="3" placeholder="Share your experience (optional)" />
                    <button wire:click="submitReview"
                        class="mt-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Submit Review
                    </button>
                </div>
            @endauth

            {{-- Review List --}}
            @forelse($reviews as $review)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900">{{ $review->user?->name ?? 'Anonymous' }}</span>
                            <div class="flex items-center">
                                @for($i = 1; $i <= 5; $i++)
                                    <span class="text-sm {{ $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300' }}">&#9733;</span>
                                @endfor
                            </div>
                        </div>
                        <span class="text-xs text-gray-400">{{ $review->created_at->diffForHumans() }}</span>
                    </div>
                    @if($review->comment)
                        <p class="mt-2 text-sm text-gray-600">{{ $review->comment }}</p>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-sm text-gray-400">No reviews yet. Be the first!</div>
            @endforelse
        </div>
    @endif
</div>
