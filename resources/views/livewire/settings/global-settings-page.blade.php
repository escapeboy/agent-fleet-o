<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div class="mb-6 border-b border-(--color-theme-border)">
        <nav class="-mb-px flex gap-6" aria-label="Settings tabs">
            @php
                $tabs = [
                    'general'    => 'General',
                    'budget'     => 'Budget & Limits',
                    'agents'     => 'Agents',
                    'tools'      => 'Tools',
                    'connectors' => 'Connectors',
                    'security'   => 'Security',
                ];
            @endphp
            @foreach($tabs as $key => $label)
                <button wire:click="$set('activeTab', '{{ $key }}')"
                    class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors
                        {{ $activeTab === $key
                            ? 'border-(--color-theme-primary) text-(--color-theme-primary)'
                            : 'border-transparent text-(--color-on-surface-muted) hover:border-(--color-theme-border-strong) hover:text-(--color-on-surface)' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ═══ General Tab ═══ --}}
    @if($activeTab === 'general')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Default Pipeline LLM --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Default Pipeline LLM</h3>
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">Used by experiment pipeline stages unless overridden per-experiment or per-agent.</p>
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

            {{-- Assistant LLM --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Assistant LLM</h3>
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">The AI model used by the platform assistant chat. Falls back to the default pipeline LLM if not set.</p>
                <form wire:submit="saveAssistantLlm" class="mt-4 space-y-4">
                    <x-form-select wire:model.live="assistantProvider" label="Provider">
                        @foreach($providers as $key => $p)
                            <option value="{{ $key }}">{{ $p['name'] }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="assistantModel" label="Model">
                        @foreach($providers[$assistantProvider]['models'] ?? [] as $modelKey => $modelInfo)
                            <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                        @endforeach
                    </x-form-select>

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Assistant LLM
                    </button>
                </form>
            </div>

            {{-- Approval Settings --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Approval Settings</h3>
                <form wire:submit="saveApprovalSettings" class="mt-4 space-y-4">
                    <x-form-input wire:model="approvalTimeoutHours" label="Default Timeout (hours)" type="number" min="1"
                        :error="$errors->first('approvalTimeoutHours')" />

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Approval Settings
                    </button>
                </form>
            </div>

            {{-- Media Analysis --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Media Analysis</h3>
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">When enabled, signals with media attachments (images, PDFs) will be automatically analyzed using vision-capable LLMs.</p>
                <form wire:submit="saveMediaAnalysisSettings" class="mt-4 space-y-4">
                    <label class="relative inline-flex cursor-pointer items-center gap-3">
                        <input type="checkbox" wire:model="mediaAnalysisEnabled" class="peer sr-only" />
                        <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                        <span class="text-sm font-medium text-(--color-on-surface)">Enable automatic media analysis</span>
                    </label>

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Media Analysis Settings
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- ═══ Budget & Limits Tab ═══ --}}
    @if($activeTab === 'budget')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Budget Settings --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Budget Settings</h3>
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
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Rate Limit Settings</h3>
                <form wire:submit="saveRateLimitSettings" class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <x-form-input wire:model="emailRateLimit" label="Email (per hour)" type="number" min="1" />
                        <x-form-input wire:model="telegramRateLimit" label="Telegram (per hour)" type="number" min="1" />
                        <x-form-input wire:model="slackRateLimit" label="Slack (per hour)" type="number" min="1" />
                        <x-form-input wire:model="webhookRateLimit" label="Webhook (per hour)" type="number" min="1" />
                        <x-form-input wire:model="discordRateLimit" label="Discord (per hour)" type="number" min="1" />
                        <x-form-input wire:model="teamsRateLimit" label="Teams (per hour)" type="number" min="1" />
                        <x-form-input wire:model="googleChatRateLimit" label="Google Chat (per hour)" type="number" min="1" />
                        <x-form-input wire:model="whatsappRateLimit" label="WhatsApp (per hour)" type="number" min="1" />
                    </div>
                    <x-form-input wire:model="targetCooldownDays" label="Target Cooldown (days)" type="number" min="0" />

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Rate Limits
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- ═══ Agents Tab ═══ --}}
    @if($activeTab === 'agents')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Agent Management --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Agent Management</h3>
                <div class="mt-4 space-y-3">
                    @forelse($agents as $agent)
                        <div class="flex items-center justify-between rounded-lg bg-(--color-surface-alt) p-3">
                            <div>
                                <p class="text-sm font-medium text-(--color-on-surface)">{{ $agent->name }}</p>
                                <p class="text-xs text-(--color-on-surface-muted)">
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
                        <p class="text-sm text-(--color-on-surface-muted)">No agents configured.</p>
                    @endforelse
                </div>
            </div>

            @selfhosted
            {{-- Local Agents (self-hosted only) --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Local Agents</h3>
                        @if($bridgeMode)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $bridgeConnected ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800' }}">
                                Bridge {{ $bridgeConnected ? 'connected' : 'unreachable' }}
                            </span>
                        @endif
                    </div>
                    <button wire:click="rescanLocalAgents"
                        class="rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                        Re-scan
                    </button>
                </div>
                <div class="mt-4 space-y-3">
                    @if($localAgentsEnabled)
                        @forelse($allLocalAgents as $key => $agentConfig)
                            @php $detected = $detectedLocalAgents[$key] ?? null; @endphp
                            <div class="flex items-center justify-between rounded-lg bg-(--color-surface-alt) p-3">
                                <div>
                                    <p class="text-sm font-medium text-(--color-on-surface)">{{ $agentConfig['name'] }}</p>
                                    <p class="text-xs text-(--color-on-surface-muted)">{{ $agentConfig['description'] }}</p>
                                </div>
                                <div>
                                    @if($detected)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                            v{{ $detected['version'] }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-(--color-surface-alt) px-2.5 py-0.5 text-xs font-medium text-(--color-on-surface-muted)">
                                            Not found
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-(--color-on-surface-muted)">No local agents configured.</p>
                        @endforelse
                    @else
                        <p class="text-sm text-(--color-on-surface-muted)">Local agents are disabled. Set <code class="text-xs">LOCAL_AGENTS_ENABLED=true</code> in .env to enable.</p>
                    @endif
                </div>
            </div>
            @endselfhosted
        </div>
    @endif

    {{-- ═══ Tools Tab ═══ --}}
    @if($activeTab === 'tools')
        @include('livewire.settings.partials.mcp-import-section')
    @endif

    {{-- ═══ Connectors Tab ═══ --}}
    @if($activeTab === 'connectors')
        <div class="space-y-6">
            {{-- Outbound Connectors Grid --}}
            <div>
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Outbound Connectors</h3>
                @selfhosted
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">Configure credentials for each delivery channel. Settings stored here override .env defaults.</p>
                @else
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">Configure your outbound delivery channels below.</p>
                @endselfhosted

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($connectorStatuses as $channelKey => $connector)
                        <div class="group relative rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-4 transition hover:border-(--color-theme-border-strong) hover:shadow-sm">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $connector['configured'] ? 'bg-primary-100 text-primary-600' : 'bg-(--color-surface-alt) text-(--color-on-surface-muted)' }}">
                                        @switch($connector['icon'])
                                            @case('paper-airplane')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                                @break
                                            @case('chat-bubble-left-right')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                                                @break
                                            @case('chat-bubble-oval-left')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 01-.923 1.785A5.969 5.969 0 006 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337z" /></svg>
                                                @break
                                            @case('building-office')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                                @break
                                            @case('chat-bubble-left')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                                                @break
                                            @case('phone')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                                @break
                                            @case('envelope')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                                @break
                                            @case('globe-alt')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>
                                                @break
                                        @endswitch
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-(--color-on-surface)">{{ $connector['label'] }}</p>
                                        <div class="mt-0.5 flex items-center gap-1.5">
                                            @if($connector['configured'])
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                                <span class="text-xs text-green-700">
                                                    @if($connector['source'] === 'ui')
                                                        Configured
                                                    @elsecloud
                                                        Platform default
                                                    @else
                                                        Via .env
                                                    @endif
                                                </span>
                                            @else
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-(--color-on-surface-muted)"></span>
                                                <span class="text-xs text-(--color-on-surface-muted)">Not configured</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Last test status --}}
                            @if($connector['lastTestedAt'])
                                <p class="mt-3 text-xs text-(--color-on-surface-muted)">
                                    Tested {{ $connector['lastTestedAt']->diffForHumans() }}
                                    @if($connector['lastTestStatus'] === 'success')
                                        — <span class="text-green-600">passed</span>
                                    @else
                                        — <span class="text-red-600">failed</span>
                                    @endif
                                </p>
                            @endif

                            {{-- Configure button --}}
                            <button
                                wire:click="$dispatchTo('settings.connector-config-modal', 'openModal', { channel: '{{ $channelKey }}' })"
                                class="mt-3 w-full rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) transition hover:bg-(--color-surface-alt)">
                                {{ $connector['configured'] ? 'Configure' : 'Set Up' }}
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Webhooks (inbound) --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                @livewire('settings.webhook-settings-panel')
            </div>
        </div>

        @cloud
        {{-- Email is platform-managed in cloud mode --}}
        <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-(--color-surface-alt) text-(--color-on-surface-muted)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-(--color-on-surface)">Email (Platform Managed)</p>
                    <p class="text-xs text-(--color-on-surface-muted)">Email delivery is handled by the platform. No configuration required.</p>
                </div>
            </div>
        </div>
        @endcloud

        {{-- Connector Config Modal (self-hosted only for email; other channels use it too) --}}
        @livewire('settings.connector-config-modal')
    @endif

    {{-- ═══ Security Tab ═══ --}}
    @if($activeTab === 'security')
        <div class="space-y-6">
            {{-- Organization Security Policy --}}
            @selfhosted
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                @livewire('settings.security-policy-panel')
            </div>
            @else
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-(--color-on-surface-muted)" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    <div>
                        <h3 class="text-sm font-medium text-(--color-on-surface)">Shell Command Execution Unavailable</h3>
                        <p class="mt-1 text-sm text-(--color-on-surface-muted)">Shell command execution is not available in cloud mode. Agents run in an isolated sandbox with no host filesystem access.</p>
                    </div>
                </div>
            </div>
            @endselfhosted

            {{-- Blacklist Management --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Blacklist Management</h3>

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
                        <div class="overflow-hidden rounded-lg border border-(--color-theme-border)">
                            <table class="min-w-full divide-y divide-(--color-theme-border)">
                                <thead class="bg-(--color-surface-alt)">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-(--color-on-surface-muted)">Type</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-(--color-on-surface-muted)">Value</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-(--color-on-surface-muted)">Reason</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-(--color-on-surface-muted)">Added</th>
                                        <th class="px-4 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-(--color-theme-border)">
                                    @foreach($blacklistEntries as $entry)
                                        <tr>
                                            <td class="px-4 py-2">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                                    @if($entry->type === 'email') bg-blue-100 text-blue-800
                                                    @elseif($entry->type === 'domain') bg-purple-100 text-purple-800
                                                    @elseif($entry->type === 'company') bg-yellow-100 text-yellow-800
                                                    @else bg-(--color-surface-alt) text-(--color-on-surface)
                                                    @endif">
                                                    {{ $entry->type }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-sm text-(--color-on-surface)">{{ $entry->value }}</td>
                                            <td class="px-4 py-2 text-sm text-(--color-on-surface-muted)">{{ $entry->reason ?? '-' }}</td>
                                            <td class="px-4 py-2 text-xs text-(--color-on-surface-muted)">{{ $entry->created_at->diffForHumans() }}</td>
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
                        <p class="text-sm text-(--color-on-surface-muted)">No blacklist entries.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
