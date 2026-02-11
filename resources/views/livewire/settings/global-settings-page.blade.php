<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Budget Settings --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Budget Settings</h3>
            <form wire:submit="saveBudgetSettings" class="mt-4 space-y-4">
                <x-form-input wire:model="globalBudgetCap" label="Global Budget Cap (credits)" type="number" min="0"
                    :error="$errors->first('globalBudgetCap')" />

                <x-form-input wire:model="defaultExperimentBudgetCap" label="Default Experiment Budget Cap (credits)" type="number" min="0"
                    :error="$errors->first('defaultExperimentBudgetCap')" />

                <x-form-input wire:model="budgetAlertThresholdPct" label="Low Budget Alert Threshold (%)" type="number" min="0" max="100"
                    :error="$errors->first('budgetAlertThresholdPct')" />

                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Budget Settings
                </button>
            </form>
        </div>

        {{-- Rate Limit Settings --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Rate Limit Settings</h3>
            <form wire:submit="saveRateLimitSettings" class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="emailRateLimit" label="Email (per hour)" type="number" min="1" />
                    <x-form-input wire:model="telegramRateLimit" label="Telegram (per hour)" type="number" min="1" />
                    <x-form-input wire:model="slackRateLimit" label="Slack (per hour)" type="number" min="1" />
                    <x-form-input wire:model="webhookRateLimit" label="Webhook (per hour)" type="number" min="1" />
                </div>
                <x-form-input wire:model="targetCooldownDays" label="Target Cooldown (days)" type="number" min="0" />

                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Rate Limits
                </button>
            </form>
        </div>

        {{-- Approval Settings --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Approval Settings</h3>
            <form wire:submit="saveApprovalSettings" class="mt-4 space-y-4">
                <x-form-input wire:model="approvalTimeoutHours" label="Default Timeout (hours)" type="number" min="1"
                    :error="$errors->first('approvalTimeoutHours')" />

                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Approval Settings
                </button>
            </form>
        </div>

        {{-- Default Pipeline LLM --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Default Pipeline LLM</h3>
            <p class="mt-1 text-xs text-gray-400">Used by experiment pipeline stages unless overridden per-experiment or per-agent.</p>
            <form wire:submit="saveDefaultLlm" class="mt-4 space-y-4">
                <x-form-select wire:model.live="defaultLlmProvider" label="Provider">
                    @foreach($providers as $key => $p)
                        <option value="{{ $key }}">{{ $p['name'] }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="defaultLlmModel" label="Model">
                    @foreach($providers[$defaultLlmProvider]['models'] ?? [] as $modelKey => $modelInfo)
                        <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                    @endforeach
                </x-form-select>

                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Default LLM
                </button>
            </form>
        </div>

        {{-- Agent Management --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Agent Management</h3>
            <div class="mt-4 space-y-3">
                @forelse($agents as $agent)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 p-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $agent->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $agent->provider }} / {{ $agent->model }}
                                @if($agent->circuitBreakerState)
                                    &middot; CB: {{ $agent->circuitBreakerState->state }}
                                    @if($agent->circuitBreakerState->failure_count > 0)
                                        ({{ $agent->circuitBreakerState->failure_count }} failures)
                                    @endif
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-status-badge :status="$agent->status->value" />
                            <button wire:click="toggleAgent('{{ $agent->id }}')" wire:confirm="Are you sure?"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition
                                {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Disabled
                                    ? 'border-green-300 text-green-700 hover:bg-green-50'
                                    : 'border-red-300 text-red-700 hover:bg-red-50' }}">
                                {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Disabled ? 'Enable' : 'Disable' }}
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No agents configured.</p>
                @endforelse
            </div>
        </div>

        {{-- Local Agents --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-medium text-gray-500">Local Agents</h3>
                    @if($bridgeMode)
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $bridgeConnected ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800' }}">
                            Bridge {{ $bridgeConnected ? 'connected' : 'unreachable' }}
                        </span>
                    @endif
                </div>
                <button wire:click="rescanLocalAgents"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Re-scan
                </button>
            </div>
            <div class="mt-4 space-y-3">
                @if($localAgentsEnabled)
                    @forelse($allLocalAgents as $key => $agentConfig)
                        @php $detected = $detectedLocalAgents[$key] ?? null; @endphp
                        <div class="flex items-center justify-between rounded-lg bg-gray-50 p-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $agentConfig['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $agentConfig['description'] }}</p>
                            </div>
                            <div>
                                @if($detected)
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                        v{{ $detected['version'] }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                        Not found
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">No local agents configured.</p>
                    @endforelse
                @else
                    <p class="text-sm text-gray-400">Local agents are disabled. Set <code class="text-xs">LOCAL_AGENTS_ENABLED=true</code> in .env to enable.</p>
                @endif
            </div>
        </div>

        {{-- Blacklist Management --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 lg:col-span-2">
            <h3 class="text-sm font-medium text-gray-500">Blacklist Management</h3>

            {{-- Add form --}}
            <form wire:submit="addBlacklistEntry" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <x-form-select wire:model="blacklistType" label="Type">
                        <option value="email">Email</option>
                        <option value="domain">Domain</option>
                        <option value="company">Company</option>
                        <option value="keyword">Keyword</option>
                    </x-form-select>
                </div>
                <div class="flex-1">
                    <x-form-input wire:model="blacklistValue" label="Value" type="text" placeholder="e.g. spam@example.com"
                        :error="$errors->first('blacklistValue')" />
                </div>
                <div class="flex-1">
                    <x-form-input wire:model="blacklistReason" label="Reason (optional)" type="text" placeholder="Why blocked?" />
                </div>
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700">
                    Add to Blacklist
                </button>
            </form>

            {{-- Entries list --}}
            <div class="mt-4">
                @if($blacklistEntries->count() > 0)
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Type</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Value</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Reason</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Added</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($blacklistEntries as $entry)
                                    <tr>
                                        <td class="px-4 py-2">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                                @if($entry->type === 'email') bg-blue-100 text-blue-800
                                                @elseif($entry->type === 'domain') bg-purple-100 text-purple-800
                                                @elseif($entry->type === 'company') bg-yellow-100 text-yellow-800
                                                @else bg-gray-100 text-gray-800
                                                @endif">
                                                {{ $entry->type }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ $entry->value }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $entry->reason ?? '-' }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">{{ $entry->created_at->diffForHumans() }}</td>
                                        <td class="px-4 py-2 text-right">
                                            <button wire:click="removeBlacklistEntry('{{ $entry->id }}')" wire:confirm="Remove this entry?"
                                                class="text-xs text-red-600 hover:text-red-800">Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-400">No blacklist entries.</p>
                @endif
            </div>
        </div>
    </div>
</div>
