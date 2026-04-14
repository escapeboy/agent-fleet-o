<div>
    @php
        $payload = $signal->payload ?? [];
        $severity = $payload['severity'] ?? 'minor';
        $severityColors = [
            'critical' => 'bg-red-100 text-red-800',
            'major' => 'bg-orange-100 text-orange-800',
            'minor' => 'bg-yellow-100 text-yellow-800',
            'cosmetic' => 'bg-gray-100 text-gray-800',
        ];
        $statusColor = $signal->status?->color() ?? 'gray';
    @endphp

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('bug-reports.index') }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-900">Bug Reports</a>
                <span class="text-gray-400">/</span>
                <span class="text-sm text-gray-500">{{ $signal->project_key ?? 'Report' }}</span>
            </div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $payload['title'] ?? 'Bug Report' }}</h1>
            <div class="flex items-center gap-2 mt-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $severityColors[$severity] ?? 'bg-gray-100 text-gray-800' }}">
                    {{ ucfirst($severity) }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800">
                    {{ $signal->status?->label() ?? 'Received' }}
                </span>
                @if($signal->project_key)
                    <span class="text-xs text-gray-500">{{ $signal->project_key }}</span>
                @endif
            </div>
        </div>

        {{-- Status actions --}}
        @if(count($allowedTransitions) > 0 && ! $signal->status?->isTerminal())
            <div class="flex items-center gap-2">
                @foreach($allowedTransitions as $transition)
                    <button
                        wire:click="updateStatus('{{ $transition->value }}')"
                        wire:confirm="Change status to {{ $transition->label() }}?"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                    >
                        {{ $transition->label() }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid grid-cols-3 gap-6">
        {{-- Left column: main content --}}
        <div class="col-span-2 space-y-6">

            {{-- Description --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-2">Description</h2>
                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $payload['description'] ?? '—' }}</p>
            </div>

            {{-- Screenshot --}}
            @if($screenshotUrl)
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Screenshot</h2>
                    <a href="{{ $screenshotUrl }}" target="_blank">
                        <img
                            src="{{ $screenshotUrl }}"
                            alt="Bug screenshot"
                            class="rounded border border-gray-200 max-w-full cursor-zoom-in hover:opacity-90 transition"
                        />
                    </a>
                </div>
            @endif

            {{-- Suspect Files --}}
            @if(!empty($payload['suspect_files']))
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Suspect Files</h2>
                    <div class="space-y-2">
                        @foreach($payload['suspect_files'] as $file)
                            @php $pct = (int) round(($file['confidence'] ?? 0) * 100); @endphp
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-mono text-gray-800 truncate">{{ $file['path'] ?? '' }}</p>
                                    <p class="text-xs text-gray-400 truncate">{{ $file['reason'] ?? '' }}</p>
                                </div>
                                <span class="shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold
                                    {{ $pct >= 80 ? 'bg-red-100 text-red-700' : ($pct >= 60 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $pct }}%
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Breadcrumbs (structured, typed) — preferred over action_log when present --}}
            @if(!empty($payload['breadcrumbs']))
                @php
                    $bcLevelColors = [
                        'error'   => 'text-red-600 bg-red-50',
                        'warning' => 'text-yellow-700 bg-yellow-50',
                        'info'    => 'text-blue-600 bg-blue-50',
                        'debug'   => 'text-gray-500 bg-gray-50',
                        'log'     => 'text-gray-600',
                    ];
                @endphp
                <div x-data="{ open: false }" class="bg-white rounded-lg border border-gray-200 p-4">
                    <button @click="open = !open" class="flex items-center justify-between w-full text-sm font-semibold text-gray-900">
                        <span>Breadcrumbs ({{ count($payload['breadcrumbs']) }})</span>
                        <span x-text="open ? '▲' : '▼'" class="text-gray-400 text-xs"></span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-auto max-h-72 space-y-0.5 font-mono text-xs">
                        @foreach($payload['breadcrumbs'] as $bc)
                            @php
                                $bcLevel    = $bc['level']    ?? 'log';
                                $bcCategory = $bc['category'] ?? '';
                                $bcData     = $bc['data']     ?? [];
                                $bcCls      = $bcLevelColors[$bcLevel] ?? 'text-gray-500';
                                $bcStack    = null;
                                $bcError    = null;

                                // Build the human-readable summary from data, keyed by category
                                $bcSummary = '';
                                if (str_starts_with($bcCategory, 'navigation')) {
                                    $from = $bcData['from'] ?? '';
                                    $to   = $bcData['to']   ?? '';
                                    $bcSummary = trim("$from → $to", ' →');
                                } elseif ($bcCategory === 'ui.click' || ($bcCategory === 'ui' && !array_key_exists('value', $bcData))) {
                                    $sel  = $bcData['selector'] ?? $bcData['target'] ?? '';
                                    $text = $bcData['text']     ?? $bcData['label']  ?? '';
                                    $bcSummary = trim('clicked '.trim($sel.' '.('' !== $text ? '"'.$text.'"' : '')));
                                    if ($bcSummary === 'clicked' && !empty($bcData)) {
                                        $pairs = [];
                                        foreach (array_slice($bcData, 0, 3) as $k => $v) {
                                            if (is_scalar($v) && '' !== (string) $v) {
                                                $pairs[] = "$k=$v";
                                            }
                                        }
                                        if ($pairs) {
                                            $bcSummary .= ' '.implode(' ', $pairs);
                                        }
                                    }
                                } elseif ($bcCategory === 'ui.input' || ($bcCategory === 'ui' && array_key_exists('value', $bcData))) {
                                    $name = $bcData['name'] ?? $bcData['target'] ?? $bcData['id'] ?? '';
                                    $bcSummary = trim('input '.$name).' (redacted)';
                                } elseif (in_array($bcCategory, ['http', 'xhr', 'fetch'])) {
                                    $method = strtoupper($bcData['method'] ?? '');
                                    $url    = $bcData['url']        ?? '';
                                    $status = $bcData['status_code'] ?? $bcData['statusCode'] ?? $bcData['status'] ?? '';
                                    $bcSummary = trim("$method $url".($status ? " → $status" : ''));
                                    $bcError = ($status >= 400 && isset($bcData['response_body']))
                                        ? $bcData['response_body']
                                        : null;
                                } elseif ($bcCategory === 'console') {
                                    $lvl = strtoupper($bcData['level'] ?? $bcLevel);
                                    $msg = $bcData['message'] ?? ($bc['message'] ?? '');
                                    $bcSummary = "$lvl: $msg";
                                } elseif ($bcCategory === 'error') {
                                    $type = $bcData['type']    ?? 'Error';
                                    $msg  = $bcData['message'] ?? ($bc['message'] ?? '');
                                    $bcSummary = "$type: $msg";
                                    $bcStack   = $bcData['stack'] ?? null;
                                } else {
                                    $bcSummary = $bc['message'] ?? '';
                                    if (empty($bcSummary) && !empty($bcData)) {
                                        // Generic fallback: key=value pairs
                                        $pairs = [];
                                        foreach (array_slice($bcData, 0, 4) as $k => $v) {
                                            if (is_scalar($v)) $pairs[] = "$k=$v";
                                        }
                                        $bcSummary = implode(' ', $pairs);
                                    }
                                }
                            @endphp
                            <div class="px-2 py-1 rounded {{ $bcCls }}">
                                <div class="flex items-start gap-2">
                                    <span class="shrink-0 text-gray-400 whitespace-nowrap">{{ $bc['timestamp'] ?? '' }}</span>
                                    @if($bcCategory)
                                        <span class="shrink-0 text-gray-400">[{{ $bcCategory }}]</span>
                                    @endif
                                    <span class="flex-1 break-all">{{ $bcSummary }}</span>
                                </div>
                                {{-- Error stack trace (collapsible) --}}
                                @if(!empty($bcStack))
                                    <div x-data="{ s: false }" class="mt-0.5 ml-2">
                                        <button @click="s = !s" class="text-gray-400 hover:text-gray-600 text-xs">
                                            <span x-text="s ? '▲ hide stack' : '▼ stack trace'"></span>
                                        </button>
                                        <pre x-show="s" x-cloak class="mt-1 whitespace-pre-wrap text-gray-500 text-xs leading-tight">{{ $bcStack }}</pre>
                                    </div>
                                @endif
                                {{-- HTTP error response body --}}
                                @if(!empty($bcError))
                                    <div class="mt-0.5 ml-2 text-red-500 break-all">{{ Str::limit($bcError, 200) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            {{-- Action Log (fallback when no structured breadcrumbs) --}}
            @elseif(!empty($payload['action_log']))
                <div x-data="{ open: false }" class="bg-white rounded-lg border border-gray-200 p-4">
                    <button @click="open = !open" class="flex items-center justify-between w-full text-sm font-semibold text-gray-900">
                        <span>Action Log ({{ count($payload['action_log']) }} events)</span>
                        <span x-text="open ? '▲' : '▼'" class="text-gray-400 text-xs"></span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-auto max-h-64">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-1 text-left text-gray-500">Time</th>
                                    <th class="px-2 py-1 text-left text-gray-500">Action</th>
                                    <th class="px-2 py-1 text-left text-gray-500">Target</th>
                                    <th class="px-2 py-1 text-left text-gray-500">Detail</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($payload['action_log'] as $entry)
                                    <tr>
                                        <td class="px-2 py-1 text-gray-400 whitespace-nowrap font-mono">{{ $entry['timestamp'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-gray-700">{{ $entry['action'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-gray-500 font-mono truncate max-w-xs">{{ $entry['target'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-gray-500 truncate max-w-xs">{{ $entry['detail'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Console Log --}}
            @if(!empty($payload['console_log']))
                <div x-data="{ open: false }" class="bg-white rounded-lg border border-gray-200 p-4">
                    <button @click="open = !open" class="flex items-center justify-between w-full text-sm font-semibold text-gray-900">
                        <span>Console Log ({{ count($payload['console_log']) }} entries)</span>
                        <span x-text="open ? '▲' : '▼'" class="text-gray-400 text-xs"></span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-auto max-h-64 font-mono text-xs space-y-0.5">
                        @foreach($payload['console_log'] as $entry)
                            @php
                                $level = $entry['level'] ?? 'log';
                                $colors = ['error' => 'text-red-600 bg-red-50', 'warn' => 'text-yellow-700 bg-yellow-50', 'info' => 'text-blue-600', 'log' => 'text-gray-600'];
                                $cls = $colors[$level] ?? 'text-gray-500';
                            @endphp
                            <div class="px-2 py-0.5 rounded {{ $cls }}">
                                <span class="text-gray-400 mr-2">{{ $entry['timestamp'] ?? '' }}</span>
                                <span class="uppercase font-semibold mr-2">{{ $level }}</span>
                                {{ $entry['message'] ?? '' }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Network Log --}}
            @if(!empty($payload['network_log']))
                <div x-data="{ open: false }" class="bg-white rounded-lg border border-gray-200 p-4">
                    <button @click="open = !open" class="flex items-center justify-between w-full text-sm font-semibold text-gray-900">
                        <span>Network Log ({{ count($payload['network_log']) }} requests)</span>
                        <span x-text="open ? '▲' : '▼'" class="text-gray-400 text-xs"></span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-auto max-h-64">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-1 text-left text-gray-500">Method</th>
                                    <th class="px-2 py-1 text-left text-gray-500">URL</th>
                                    <th class="px-2 py-1 text-left text-gray-500">Status</th>
                                    <th class="px-2 py-1 text-left text-gray-500">Duration</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($payload['network_log'] as $req)
                                    @php $statusCode = (int)($req['status'] ?? 0); @endphp
                                    <tr>
                                        <td class="px-2 py-1 font-mono font-semibold text-gray-700">{{ $req['method'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-gray-500 truncate max-w-xs font-mono">{{ $req['url'] ?? '' }}</td>
                                        <td class="px-2 py-1 {{ $statusCode >= 400 ? 'text-red-600 font-semibold' : 'text-green-600' }}">{{ $req['status'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-gray-500">{{ isset($req['duration_ms']) ? $req['duration_ms'].'ms' : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Comments --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Comments</h2>

                @if($signal->comments->isEmpty())
                    <p class="text-sm text-gray-400 mb-4">No comments yet.</p>
                @else
                    <div class="space-y-3 mb-4">
                        @foreach($signal->comments as $comment)
                            <div class="flex items-start gap-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold
                                    {{ $comment->author_type === 'agent' ? 'bg-purple-100 text-purple-700' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $comment->author_type === 'agent' ? 'AI' : substr($comment->user?->name ?? 'U', 0, 1) }}
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <span class="text-xs font-medium text-gray-800">
                                            {{ $comment->author_type === 'agent' ? 'Agent' : ($comment->user?->name ?? 'Unknown') }}
                                        </span>
                                        <span class="text-xs text-gray-400">{{ $comment->created_at?->diffForHumans() }}</span>
                                    </div>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $comment->body }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex gap-2 items-start">
                    <div class="flex-1">
                        <x-form-textarea
                            wire:model="commentText"
                            rows="2"
                            placeholder="Add a comment..."
                            :error="$errors->first('commentText')"
                        />
                    </div>
                    <button
                        wire:click="addComment"
                        class="px-3 py-2.5 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700"
                    >
                        Post
                    </button>
                </div>
            </div>
        </div>

        {{-- Right column: metadata + delegate --}}
        <div class="space-y-4">

            {{-- Metadata --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                <h2 class="text-sm font-semibold text-gray-900">Details</h2>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-gray-500">Reporter</dt>
                        <dd class="text-gray-800 font-medium">{{ $payload['reporter_name'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Page URL</dt>
                        <dd class="text-gray-800 truncate">
                            <a href="{{ $payload['url'] ?? '#' }}" target="_blank" class="text-primary-600 hover:underline">
                                {{ $payload['url'] ?? '—' }}
                            </a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Environment</dt>
                        <dd class="text-gray-800">{{ ucfirst($payload['environment'] ?? '—') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Viewport</dt>
                        <dd class="text-gray-800">{{ $payload['viewport'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Browser</dt>
                        <dd class="text-gray-800 text-xs truncate">{{ $payload['browser'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Reported</dt>
                        <dd class="text-gray-800">{{ $signal->created_at?->diffForHumans() }}</dd>
                    </div>
                    @if($signal->experiment_id)
                        <div>
                            <dt class="text-gray-500">Experiment</dt>
                            <dd>
                                <a href="{{ route('experiments.show', $signal->experiment_id) }}" wire:navigate class="text-primary-600 hover:underline text-xs font-mono">
                                    {{ substr($signal->experiment_id, 0, 8) }}…
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Deploy Info --}}
            @if(!empty($payload['deploy_commit']) || !empty($payload['deploy_timestamp']))
                <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900">Deploy</h2>
                    @if(!empty($payload['deploy_commit']))
                        <div>
                            <dt class="text-xs text-gray-500">Commit</dt>
                            <dd class="text-xs font-mono text-gray-800">{{ substr($payload['deploy_commit'], 0, 12) }}</dd>
                        </div>
                    @endif
                    @if(!empty($payload['deploy_timestamp']))
                        <div>
                            <dt class="text-xs text-gray-500">Deployed at</dt>
                            <dd class="text-xs text-gray-800">{{ $payload['deploy_timestamp'] }}</dd>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Source Hints (route match) --}}
            @php $routeHint = $payload['source_hints']['route'] ?? null; @endphp
            @if($routeHint)
                <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900">Route Match</h2>
                    @if(!empty($routeHint['name']))
                        <div>
                            <dt class="text-xs text-gray-500">Route name</dt>
                            <dd class="text-xs font-mono text-gray-800">{{ $routeHint['name'] }}</dd>
                        </div>
                    @endif
                    @if(!empty($routeHint['uri']))
                        <div>
                            <dt class="text-xs text-gray-500">URI</dt>
                            <dd class="text-xs font-mono text-gray-800">{{ $routeHint['uri'] }}</dd>
                        </div>
                    @endif
                    @if(!empty($routeHint['controller']))
                        <div>
                            <dt class="text-xs text-gray-500">Controller</dt>
                            <dd class="text-xs font-mono text-gray-700 break-all">{{ $routeHint['controller'] }}</dd>
                        </div>
                    @endif
                    @if(!empty($routeHint['livewire_component']))
                        <div>
                            <dt class="text-xs text-gray-500">Livewire</dt>
                            <dd class="text-xs font-mono text-gray-700 break-all">{{ $routeHint['livewire_component'] }}</dd>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Delegate to Agent --}}
            @if(! in_array($signal->status, [\App\Domain\Signal\Enums\SignalStatus::DelegatedToAgent, \App\Domain\Signal\Enums\SignalStatus::AgentFixing, \App\Domain\Signal\Enums\SignalStatus::Review, \App\Domain\Signal\Enums\SignalStatus::Resolved, \App\Domain\Signal\Enums\SignalStatus::Dismissed]))
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Delegate to Agent</h2>
                    <x-form-select
                        wire:model="delegateAgentId"
                        :error="$errors->first('delegateAgentId')"
                    >
                        <option value="">Select agent...</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </x-form-select>
                    <button
                        wire:click="delegateToAgent"
                        wire:loading.attr="disabled"
                        class="mt-2 w-full px-3 py-2.5 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="delegateToAgent">Delegate</span>
                        <span wire:loading wire:target="delegateToAgent">Delegating…</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
