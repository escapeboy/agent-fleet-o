<div>
    {{-- Flash messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div class="mb-6 border-b border-(--color-theme-border)">
        <nav class="-mb-px flex gap-6 overflow-x-auto scrollbar-none" aria-label="Settings tabs">
            @php
                $tabs = array_filter([
                    'general'    => 'General',
                    'updates'    => app(\App\Domain\Shared\Services\DeploymentMode::class)->isSelfHosted() ? 'Updates' : null,
                    'budget'     => 'Budget & Limits',
                    'agents'     => 'Agents',
                    'tools'      => 'Tools',
                    'connectors' => 'Connectors',
                    'ai_routing' => 'AI Routing',
                    'security'   => 'Security',
                ]);
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

                    @php $defaultLlmModels = $providers[$defaultLlmProvider]['models'] ?? []; @endphp
                    @if(empty($defaultLlmModels) && !empty($providers[$defaultLlmProvider]['http_local']))
                        <p class="text-xs text-amber-600">No models found — ensure the endpoint is reachable and has models pulled.</p>
                    @else
                        <x-form-select wire:model="defaultLlmModel" label="Model">
                            @foreach($defaultLlmModels as $modelKey => $modelInfo)
                                <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                            @endforeach
                        </x-form-select>
                    @endif

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

                    @php $assistantModels = $providers[$assistantProvider]['models'] ?? []; @endphp
                    @if(empty($assistantModels) && !empty($providers[$assistantProvider]['http_local']))
                        <p class="text-xs text-amber-600">No models found — ensure the endpoint is reachable and has models pulled.</p>
                    @else
                        <x-form-select wire:model="assistantModel" label="Model">
                            @foreach($assistantModels as $modelKey => $modelInfo)
                                <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                            @endforeach
                        </x-form-select>
                    @endif

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

            {{-- Platform AI Defaults --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Platform AI Defaults</h3>
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">Default values used when teams have not set their own overrides.</p>
                <form wire:submit="savePlatformAiDefaults" class="mt-4 space-y-4">
                    <x-form-input wire:model="defaultExperimentTtl" label="Experiment TTL (minutes)" type="number" min="5" max="1440"
                        hint="Maximum wall-clock time for experiment execution"
                        :error="$errors->first('defaultExperimentTtl')" />

                    <div class="grid grid-cols-3 gap-4">
                        <x-form-input wire:model="skillReliabilityThreshold" label="Skill Reliability Threshold" type="number" step="0.05" min="0" max="1"
                            hint="Min reliability rate before degradation alert"
                            :error="$errors->first('skillReliabilityThreshold')" />
                        <x-form-input wire:model="skillQualityThreshold" label="Skill Quality Threshold" type="number" step="0.05" min="0" max="1"
                            hint="Min quality score before degradation alert"
                            :error="$errors->first('skillQualityThreshold')" />
                        <x-form-input wire:model="skillMinSampleSize" label="Min Sample Size" type="number" min="1" max="1000"
                            hint="Executions required before degradation check"
                            :error="$errors->first('skillMinSampleSize')" />
                    </div>

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Platform AI Defaults
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

    {{-- ═══ Updates Tab — self-hosted only ═══ --}}
    @selfhosted
    @if($activeTab === 'updates')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Current Version --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Version Information</h3>
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-(--color-on-surface-muted)">Installed version</span>
                        <span class="font-mono font-semibold text-(--color-on-surface)">v{{ $installedVersion }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-(--color-on-surface-muted)">Latest version</span>
                        @if($latestVersion)
                            <span class="font-mono font-semibold
                                {{ $updateAvailable ? 'text-amber-600' : 'text-green-600' }}">
                                v{{ ltrim($latestVersion, 'v') }}
                            </span>
                        @else
                            <span class="text-(--color-on-surface-muted) italic">unknown</span>
                        @endif
                    </div>
                    @if($updateAvailable && $updateInfo)
                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            <strong>Update available!</strong>
                            @if($updateInfo['release_url'])
                                <a href="{{ $updateInfo['release_url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="ml-1 underline hover:no-underline">View release notes</a>
                            @endif
                        </div>
                    @elseif($latestVersion)
                        <p class="text-xs text-green-600">You are running the latest version.</p>
                    @endif
                </div>
                <div class="mt-4">
                    <button wire:click="forceUpdateCheck"
                            type="button"
                            class="rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                        Check Now
                    </button>
                </div>
            </div>

            {{-- Update Check Settings --}}
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Update Check Settings</h3>
                <p class="mt-1 text-xs text-(--color-on-surface-muted)">
                    When enabled, FleetQ checks GitHub Releases once per hour to detect new versions.
                    No personal data is sent — only a version comparison request is made.
                </p>
                <form wire:submit="saveUpdateSettings" class="mt-4 space-y-4">
                    <label class="relative inline-flex cursor-pointer items-center gap-3">
                        <input type="checkbox" wire:model="updateCheckEnabled" class="peer sr-only" />
                        <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                        <span class="text-sm font-medium text-(--color-on-surface)">Enable automatic update checks</span>
                    </label>

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save
                    </button>
                </form>

                <div class="mt-4 border-t border-(--color-theme-border) pt-4 text-xs text-(--color-on-surface-muted)">
                    <p>Update checks are performed hourly by the scheduler.</p>
                    <p class="mt-1">Source:
                        <a href="https://github.com/{{ config('app.github_repo') }}/releases"
                           target="_blank" rel="noopener noreferrer"
                           class="underline hover:no-underline">
                            github.com/{{ config('app.github_repo') }}
                        </a>
                    </p>
                </div>
            </div>
        </div>
    @endif
    @endselfhosted

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
                                {{ $relayMode ? 'Relay' : 'Bridge' }} {{ $bridgeConnected ? 'connected' : 'unreachable' }}
                            </span>
                        @endif
                    </div>
                    <button wire:click="rescanLocalAgents"
                        @if(!$relayMode && $bridgeSecretMissing) disabled title="Configure LOCAL_AGENT_BRIDGE_SECRET first"
                        @elseif($bridgeMode && !$bridgeConnected) disabled title="Bridge is not connected" @endif
                        class="rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt) disabled:opacity-40 disabled:cursor-not-allowed">
                        Re-scan
                    </button>
                </div>

                {{-- Relay mode: bridge not connected --}}
                @if($relayMode && !$bridgeConnected)
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 text-base shrink-0 text-amber-500"></i>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">FleetQ Bridge not connected</p>
                            <p class="mt-1 text-xs text-amber-700">
                                <strong>Step 1.</strong> Make sure the relay service is running:
                            </p>
                            <pre class="mt-1 overflow-x-auto rounded bg-amber-100 p-2 text-xs text-amber-900">docker compose --profile relay up -d relay</pre>
                            <p class="mt-2 text-xs text-amber-700">
                                <strong>Step 2.</strong> Install and connect the bridge on your machine:
                            </p>
                            <pre class="mt-1 overflow-x-auto rounded bg-amber-100 p-2 text-xs text-amber-900"># Download (macOS / Linux)
curl -sSL https://get.fleetq.net | sh

# Authenticate
fleetq-bridge login --api-url {{ config('app.url') }} --api-key YOUR_API_TOKEN

# Install as a background service
fleetq-bridge install</pre>
                            <p class="mt-1 text-xs text-amber-700">
                                Get your API token from <a href="{{ route('team.settings') }}" class="underline">Team Settings → API Tokens</a>.
                            </p>
                        </div>
                    </div>
                @endif

                {{-- Relay mode: bridge connected --}}
                @if($relayMode && $bridgeConnected && $bridgeConnection)
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                        <i class="fa-solid fa-circle-check mt-0.5 text-base shrink-0 text-green-500"></i>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">Bridge connected</p>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-green-700">
                                <span>{{ $bridgeConnection->ip_address }}</span>
                                @if($bridgeConnection->bridge_version)
                                    <span>v{{ $bridgeConnection->bridge_version }}</span>
                                @endif
                                <span>Connected {{ $bridgeConnection->connected_at?->diffForHumans() }}</span>
                                <span>Last seen {{ $bridgeConnection->last_seen_at?->diffForHumans() }}</span>
                            </div>
                            @php
                                $llmCount  = $bridgeConnection->onlineLlmCount();
                                $agentCount = $bridgeConnection->foundAgentCount();
                                $mcpCount  = count($bridgeConnection->mcpServers());
                            @endphp
                            @if($llmCount + $agentCount + $mcpCount > 0)
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if($llmCount > 0)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                                            {{ $llmCount }} LLM{{ $llmCount !== 1 ? 's' : '' }} online
                                        </span>
                                    @endif
                                    @if($agentCount > 0)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                                            {{ $agentCount }} agent{{ $agentCount !== 1 ? 's' : '' }} found
                                        </span>
                                    @endif
                                    @if($mcpCount > 0)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                                            {{ $mcpCount }} MCP server{{ $mcpCount !== 1 ? 's' : '' }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Legacy bridge: secret not configured warning (non-relay mode) --}}
                @if(!$relayMode && $bridgeSecretMissing)
                    <div class="mt-4 space-y-3">
                        {{-- Option A: relay (recommended) --}}
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                            <p class="font-medium">Option A — Relay <span class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-semibold text-blue-700">Recommended</span></p>
                            <p class="mt-1 text-xs text-blue-700">
                                A Go-based relay daemon runs inside Docker and bridges your local agents over WebSocket. No host port forwarding required.
                            </p>
                            <ol class="mt-2 space-y-1 text-xs text-blue-700 list-decimal list-inside">
                                <li>Set <code class="rounded bg-blue-100 px-1">RELAY_ENABLED=true</code> in <code class="rounded bg-blue-100 px-1">.env</code></li>
                                <li>Start the relay: <code class="rounded bg-blue-100 px-1">docker compose --profile relay up -d relay</code></li>
                                <li>Install the bridge on your machine and authenticate:
                                    <pre class="mt-1 overflow-x-auto rounded bg-blue-100 p-2 text-xs text-blue-900">fleetq-bridge login --api-url {{ config('app.url') }} --api-key YOUR_API_TOKEN
fleetq-bridge install</pre>
                                </li>
                            </ol>
                            <p class="mt-1 text-xs text-blue-600">
                                Get your API token from <a href="{{ route('team.settings') }}" class="underline">Team Settings → API Tokens</a>.
                            </p>
                        </div>

                        {{-- Option B: legacy PHP bridge --}}
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            <p class="font-medium">Option B — Legacy PHP bridge</p>
                            <p class="mt-1 text-xs text-amber-700">
                                Single-threaded PHP bridge that runs on your host machine. Add to <code class="rounded bg-amber-100 px-1">.env</code>:
                            </p>
                            <pre class="mt-1 overflow-x-auto rounded bg-amber-100 p-2 text-xs text-amber-900">LOCAL_AGENT_BRIDGE_SECRET=your-secret</pre>
                            <p class="mt-1 text-xs text-amber-700">Then start the bridge on your host:</p>
                            <pre class="mt-1 overflow-x-auto rounded bg-amber-100 p-2 text-xs text-amber-900">LOCAL_AGENT_BRIDGE_SECRET=your-secret php -S 0.0.0.0:8065 docker/host-bridge.php</pre>
                        </div>
                    </div>
                @endif

                {{-- Legacy bridge: not connected warning (non-relay mode) --}}
                @if(!$relayMode && $bridgeMode && !$bridgeConnected)
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 text-base shrink-0 text-amber-500"></i>
                        <div>
                            <p class="font-medium">Bridge daemon is not running</p>
                            <p class="mt-0.5 text-xs text-amber-700">
                                Local agent scanning requires the FleetQ Bridge daemon to be running on your host machine.
                                Start it with: <code class="rounded bg-amber-100 px-1">php /path/to/host-bridge.php</code>
                            </p>
                        </div>
                    </div>
                @endif

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
                                                <i class="fa-solid fa-paper-plane text-lg"></i>
                                                @break
                                            @case('chat-bubble-left-right')
                                                <i class="fa-solid fa-comments text-lg"></i>
                                                @break
                                            @case('chat-bubble-oval-left')
                                                <i class="fa-regular fa-comment text-lg"></i>
                                                @break
                                            @case('building-office')
                                                <i class="fa-solid fa-building text-lg"></i>
                                                @break
                                            @case('chat-bubble-left')
                                                <i class="fa-solid fa-comment text-lg"></i>
                                                @break
                                            @case('phone')
                                                <i class="fa-solid fa-phone text-lg"></i>
                                                @break
                                            @case('envelope')
                                                <i class="fa-solid fa-envelope text-lg"></i>
                                                @break
                                            @case('globe-alt')
                                                <i class="fa-solid fa-globe text-lg"></i>
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

        {{-- Connector Config Modal --}}
        @livewire('settings.connector-config-modal')
    @endif

    {{-- ═══ AI Routing Tab ═══ --}}
    @if($activeTab === 'ai_routing')
        <form wire:submit="saveAiRoutingSettings">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Budget Pressure Routing --}}
                <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                    <h3 class="text-sm font-medium text-(--color-on-surface)">Budget Pressure Routing</h3>
                    <p class="mt-1 text-xs text-(--color-on-surface-muted)">Automatically downgrade model tiers as monthly budget consumption increases. At each threshold, requests shift to cheaper models.</p>
                    <div class="mt-4 space-y-4">
                        <label class="relative inline-flex cursor-pointer items-center gap-3">
                            <input type="checkbox" wire:model="budgetPressureEnabled" class="peer sr-only" />
                            <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                            <span class="text-sm font-medium text-(--color-on-surface)">Enable budget pressure routing</span>
                        </label>

                        <div class="grid grid-cols-3 gap-4">
                            <x-form-input wire:model="budgetPressureLow" label="Low %" type="number" min="0" max="100"
                                hint="Heavy → standard"
                                :error="$errors->first('budgetPressureLow')" />
                            <x-form-input wire:model="budgetPressureMedium" label="Medium %" type="number" min="0" max="100"
                                hint="Standard → light"
                                :error="$errors->first('budgetPressureMedium')" />
                            <x-form-input wire:model="budgetPressureHigh" label="High %" type="number" min="0" max="100"
                                hint="All → cheapest"
                                :error="$errors->first('budgetPressureHigh')" />
                        </div>
                    </div>
                </div>

                {{-- Model Escalation --}}
                <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                    <h3 class="text-sm font-medium text-(--color-on-surface)">Model Escalation</h3>
                    <p class="mt-1 text-xs text-(--color-on-surface-muted)">When an AI call fails due to quality issues, retry with a stronger model before falling back to another provider.</p>
                    <div class="mt-4 space-y-4">
                        <label class="relative inline-flex cursor-pointer items-center gap-3">
                            <input type="checkbox" wire:model="escalationEnabled" class="peer sr-only" />
                            <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                            <span class="text-sm font-medium text-(--color-on-surface)">Enable model escalation</span>
                        </label>

                        <x-form-input wire:model="escalationMaxAttempts" label="Max Escalation Attempts" type="number" min="1" max="5"
                            hint="How many tiers up to try (light → standard → heavy)"
                            :error="$errors->first('escalationMaxAttempts')" />
                    </div>
                </div>

                {{-- Verification Gate --}}
                <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                    <h3 class="text-sm font-medium text-(--color-on-surface)">Verification Gate</h3>
                    <p class="mt-1 text-xs text-(--color-on-surface-muted)">Run mechanical verification on pipeline stage output. On failure, inject error context and retry within the same job.</p>
                    <div class="mt-4 space-y-4">
                        <label class="relative inline-flex cursor-pointer items-center gap-3">
                            <input type="checkbox" wire:model="verificationEnabled" class="peer sr-only" />
                            <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                            <span class="text-sm font-medium text-(--color-on-surface)">Enable verification gate</span>
                        </label>

                        <x-form-input wire:model="verificationMaxRetries" label="Max Retries" type="number" min="1" max="5"
                            hint="Retry attempts before marking stage as failed"
                            :error="$errors->first('verificationMaxRetries')" />
                    </div>
                </div>

                {{-- Stuck Detection --}}
                <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
                    <h3 class="text-sm font-medium text-(--color-on-surface)">Stuck Detection</h3>
                    <p class="mt-1 text-xs text-(--color-on-surface-muted)">Sliding-window pattern analysis over recent state transitions to detect loops, oscillations, and stalls.</p>
                    <div class="mt-4 space-y-4">
                        <label class="relative inline-flex cursor-pointer items-center gap-3">
                            <input type="checkbox" wire:model="stuckDetectionEnabled" class="peer sr-only" />
                            <div class="peer h-6 w-11 shrink-0 rounded-full bg-(--color-surface-alt) after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-(--color-theme-border-strong) after:bg-(--color-surface-raised) after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300"></div>
                            <span class="text-sm font-medium text-(--color-on-surface)">Enable stuck detection</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save AI Routing Settings
                </button>
            </div>
        </form>
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
                    <i class="fa-solid fa-circle-info mt-0.5 text-lg shrink-0 text-(--color-on-surface-muted)"></i>
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
