<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Header meta + actions --}}
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="flex-1 space-y-1">
            <div class="flex flex-wrap gap-2">
                @php
                    $modeColors = ['api_only' => 'blue', 'sandbox' => 'purple', 'bridge' => 'orange'];
                    $color = $modeColors[$gitRepository->mode->value] ?? 'gray';
                    $statusColors = ['active' => 'green', 'disabled' => 'gray', 'error' => 'red'];
                    $sColor = $statusColors[$gitRepository->status->value] ?? 'gray';
                @endphp
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $color }}-100 text-{{ $color }}-800">
                    {{ $gitRepository->mode->label() }}
                </span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $sColor }}-100 text-{{ $sColor }}-800">
                    {{ $gitRepository->status->label() }}
                </span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-700">
                    {{ $gitRepository->provider->label() }}
                </span>
            </div>
            <p class="text-sm text-gray-500">{{ $gitRepository->url }}</p>
            <p class="text-xs text-gray-400">
                Default branch: <span class="font-medium text-gray-600">{{ $gitRepository->default_branch }}</span>
                @if($gitRepository->last_ping_at)
                    · Last ping: {{ $gitRepository->last_ping_at->diffForHumans() }}
                @endif
            </p>
        </div>

        <button wire:click="testConnection" wire:loading.attr="disabled"
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
            <span wire:loading.remove wire:target="testConnection">Test Connection</span>
            <span wire:loading wire:target="testConnection">Testing…</span>
        </button>
    </div>

    {{-- Test result --}}
    @if($testMessage)
        <div @class([
            'mb-6 rounded-lg p-3 text-sm',
            'bg-green-50 text-green-700' => $testSuccess,
            'bg-red-50 text-red-700' => !$testSuccess,
        ])>
            {{ $testMessage }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Left: Config --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h3 class="mb-4 text-sm font-semibold text-gray-900">Configuration</h3>
                <dl class="space-y-3 text-sm">
                    @if($gitRepository->credential)
                        <div>
                            <dt class="text-gray-500">Credential</dt>
                            <dd class="font-medium text-gray-800">{{ $gitRepository->credential->name }}</dd>
                        </div>
                    @endif
                    @if($gitRepository->mode->value === 'sandbox' && !empty($gitRepository->config['sandbox']))
                        <div>
                            <dt class="text-gray-500">Sandbox Provider</dt>
                            <dd class="font-medium text-gray-800">{{ $gitRepository->config['sandbox']['provider'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Instance Type</dt>
                            <dd class="font-medium text-gray-800">{{ $gitRepository->config['sandbox']['instance_type'] ?? '—' }}</dd>
                        </div>
                    @endif
                    @if($gitRepository->mode->value === 'bridge' && !empty($gitRepository->config['bridge']))
                        <div>
                            <dt class="text-gray-500">Bridge Repo</dt>
                            <dd class="font-medium text-gray-800">{{ $gitRepository->config['bridge']['repo_name'] ?? '—' }}</dd>
                        </div>
                        @if(!empty($gitRepository->config['bridge']['working_directory']))
                            <div>
                                <dt class="text-gray-500">Working Dir</dt>
                                <dd class="font-mono text-xs text-gray-800">{{ $gitRepository->config['bridge']['working_directory'] }}</dd>
                            </div>
                        @endif
                    @endif
                    <div>
                        <dt class="text-gray-500">PR Approval</dt>
                        <dd class="font-medium text-gray-800">
                            {{ ($gitRepository->config['pr']['require_approval'] ?? false) ? 'Required' : 'Not required' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Right: Operations + PRs --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Recent operations --}}
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Recent Operations</h3>
                </div>
                @if($operations->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-400">No operations yet.</p>
                @else
                    <table class="min-w-full divide-y divide-gray-100">
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach($operations as $op)
                                @php
                                    $opStatusColors = ['pending' => 'gray', 'running' => 'blue', 'completed' => 'green', 'failed' => 'red'];
                                    $opColor = $opStatusColors[$op->status->value] ?? 'gray';
                                @endphp
                                <tr>
                                    <td class="px-5 py-3 text-sm text-gray-700">{{ $op->type->label() }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-{{ $opColor }}-100 text-{{ $opColor }}-700">
                                            {{ $op->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-400">{{ $op->created_at->diffForHumans() }}</td>
                                    @if($op->error_message)
                                        <td class="px-5 py-3 max-w-xs truncate text-xs text-red-500">{{ $op->error_message }}</td>
                                    @else
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Test Ratchet Mode --}}
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Test Ratchet Guard</h3>
                    <p class="mt-1 text-xs text-gray-500">Detects test deletions and assertion removals on every commit through this repo's GatedGitClient.</p>
                </div>
                <div class="p-5 space-y-3">
                    <x-form-select wire:model="testRatchetMode" label="Mode">
                        @foreach($testRatchetModes as $mode)
                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                        @endforeach
                    </x-form-select>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-500">
                            <strong>Off</strong>: never inspect. <strong>Soft</strong>: log a warning. <strong>Hard</strong>: refuse the commit and raise an ActionProposal.
                        </p>
                        <button wire:click="saveTestRatchetMode" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                            Save
                        </button>
                    </div>
                    @if($testRatchetSavedMessage)
                        <p class="text-xs text-emerald-600">{{ $testRatchetSavedMessage }}</p>
                    @endif
                </div>
            </div>

            {{-- Open pull requests --}}
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Open Pull Requests</h3>
                </div>
                @if($pullRequests->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-400">No open pull requests.</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach($pullRequests as $pr)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $pr->title }}</p>
                                    <p class="text-xs text-gray-500">{{ $pr->head_branch }} → {{ $pr->base_branch }}</p>
                                </div>
                                <a href="{{ $pr->pr_url }}" target="_blank" rel="noopener"
                                    class="text-sm font-medium text-primary-600 hover:text-primary-700">
                                    #{{ $pr->pr_number }} →
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
