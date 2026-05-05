<div>
    {{-- Flash messages --}}
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700">{{ session('message') }}</div>
    @endif

    @if(session('generated_token'))
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-4">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-key mt-0.5 text-lg shrink-0 text-green-600"></i>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-green-800">New API token (shown once)</p>
                    <code class="mt-1 block break-all rounded bg-green-100 px-2 py-1 text-xs font-mono text-green-900">{{ session('generated_token') }}</code>
                    <p class="mt-1 text-xs text-green-700">Copy this now. It will not be shown again.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            @php
                $statusColors = [
                    'active' => 'bg-green-100 text-green-700',
                    'inactive' => 'bg-yellow-100 text-yellow-700',
                    'draft' => 'bg-gray-100 text-gray-600',
                    'suspended' => 'bg-red-100 text-red-700',
                ];
                $colorClass = $statusColors[$chatbot->status->value] ?? 'bg-gray-100 text-gray-600';
            @endphp
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $colorClass }}">
                {{ $chatbot->status->label() }}
            </span>
            <span class="text-sm text-gray-500">{{ $chatbot->type->label() }}</span>
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="toggleStatus"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $chatbot->status->isActive() ? 'Deactivate' : 'Activate' }}
            </button>
            <button wire:click="delete"
                    wire:confirm="Are you sure you want to delete this chatbot? This action cannot be undone."
                    class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">
                Delete
            </button>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto scrollbar-none">
            @foreach(['overview' => 'Overview', 'configuration' => 'Configuration', 'tokens' => 'API Tokens', 'channels' => 'Channels', 'widget' => 'Widget', 'conversations' => 'Conversations', 'knowledge' => 'Knowledge Base', 'analytics' => 'Analytics'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                        class="whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition
                            {{ $activeTab === $tab
                                ? 'border-primary-500 text-primary-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Overview Tab --}}
    @if($activeTab === 'overview')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900">Chatbot Details</h3>

                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Slug</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-700">{{ $chatbot->slug }}</dd>
                    </div>
                    @if($chatbot->description)
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Description</dt>
                            <dd class="mt-1 text-sm text-gray-700">{{ $chatbot->description }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Welcome Message</dt>
                        <dd class="mt-1 text-sm text-gray-700">{{ $chatbot->welcome_message ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Fallback Message</dt>
                        <dd class="mt-1 text-sm text-gray-700">{{ $chatbot->fallback_message ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900">Backing Agent</h3>

                @if($chatbot->agent)
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Agent</dt>
                            <dd class="mt-1 text-sm">
                                @if($chatbot->agent_is_dedicated)
                                    <a href="{{ route('agents.show', $chatbot->agent) }}" class="text-primary-600 hover:text-primary-800">
                                        Dedicated agent ↗
                                    </a>
                                    <span class="text-xs text-gray-400">(auto-created)</span>
                                @else
                                    <a href="{{ route('agents.show', $chatbot->agent) }}" class="text-primary-600 hover:text-primary-800">
                                        {{ $chatbot->agent->name }}
                                    </a>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Provider / Model</dt>
                            <dd class="mt-1 text-sm text-gray-700">{{ $chatbot->agent->provider }} / {{ $chatbot->agent->model }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Budget</dt>
                            <dd class="mt-1 text-sm text-gray-700">
                                {{ $chatbot->agent->budget_cap_credits ? $chatbot->agent->budget_spent_credits . ' / ' . $chatbot->agent->budget_cap_credits . ' credits' : 'No cap' }}
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-400">Agent not found.</p>
                @endif
            </div>
        </div>

    {{-- Configuration Tab --}}
    @elseif($activeTab === 'configuration')
        @if($editing)
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900">Edit Configuration</h3>

                <x-form-input wire:model="editName" label="Name" :error="$errors->first('editName')" />
                <x-form-textarea wire:model="editDescription" label="Description" rows="2" />
                <x-form-input wire:model="editWelcomeMessage" label="Welcome Message" />
                <x-form-input wire:model="editFallbackMessage" label="Fallback Message" />

                <div>
                    <x-form-input wire:model="editConfidenceThreshold" type="number" step="0.05" min="0.1" max="1.0"
                        label="Confidence Threshold"
                        hint="Responses below this confidence level trigger human escalation (if enabled)."
                        :error="$errors->first('editConfidenceThreshold')" />
                </div>

                <x-form-checkbox wire:model="editHumanEscalationEnabled" label="Enable human escalation for low-confidence responses" />

                <div class="border-t border-gray-100 pt-4">
                    <x-form-select wire:model="editWorkflowId" label="Workflow" hint="Route chatbot messages through a workflow instead of directly to the agent.">
                        <option value="">— No workflow (use direct agent)</option>
                        @foreach($workflows as $workflow)
                            <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                        @endforeach
                    </x-form-select>
                </div>

                @if($chatbot->agent_is_dedicated)
                    <div class="border-t border-gray-100 pt-4 space-y-4">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">LLM Configuration</h4>

                        <x-form-select wire:model.live="editProvider" label="Provider">
                            @foreach($providers as $key => $providerData)
                                <option value="{{ $key }}">{{ $providerData['name'] }}</option>
                            @endforeach
                        </x-form-select>

                        <x-form-select wire:model="editModel" label="Model">
                            @foreach($providers[$editProvider]['models'] ?? [] as $modelKey => $modelData)
                                <option value="{{ $modelKey }}">{{ $modelData['label'] ?? $modelKey }}</option>
                            @endforeach
                        </x-form-select>

                        <x-form-textarea wire:model="editSystemPrompt" label="System Prompt" rows="4"
                            hint="Instructions for the AI agent. Leave blank to keep current prompt." />
                    </div>
                @endif

                <div class="flex justify-end gap-3 pt-2">
                    <button wire:click="cancelEdit"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="saveEdit"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Changes
                    </button>
                </div>
            </div>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Configuration</h3>
                    <button wire:click="startEdit"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Edit
                    </button>
                </div>

                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Confidence Threshold</dt>
                        <dd class="mt-1 text-sm text-gray-700">{{ number_format((float) $chatbot->confidence_threshold, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Human Escalation</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $chatbot->human_escalation_enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $chatbot->human_escalation_enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Workflow</dt>
                        <dd class="mt-1 text-sm">
                            @if($chatbot->workflow_id && ($wf = $workflows->firstWhere('id', $chatbot->workflow_id)))
                                <a href="{{ route('workflows.show', $wf->id) }}" class="text-primary-600 hover:text-primary-800">{{ $wf->name }} ↗</a>
                            @else
                                <span class="text-gray-500">Direct agent (no workflow)</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        @endif

    {{-- Tokens Tab --}}
    @elseif($activeTab === 'tokens')
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500">API tokens authenticate the embedded widget and direct API calls.</p>
                <button wire:click="$set('showNewTokenModal', true)"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                    Generate Token
                </button>
            </div>

            {{-- New token form --}}
            @if($showNewTokenModal)
                <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 space-y-3">
                    <h4 class="text-sm font-semibold text-primary-900">Generate New Token</h4>
                    <x-form-input wire:model="newTokenName" label="Token Name" placeholder="Production widget" />
                    <div class="flex gap-2">
                        <button wire:click="generateToken"
                                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                            Generate
                        </button>
                        <button wire:click="$set('showNewTokenModal', false)"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif

            {{-- Tokens table --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Prefix</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Used</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($tokens as $token)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $token->name }}</td>
                                <td class="px-6 py-3 font-mono text-sm text-gray-500">{{ $token->token_prefix }}...</td>
                                <td class="px-6 py-3 text-sm text-gray-500">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="px-6 py-3">
                                    @if($token->revoked_at)
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Revoked</span>
                                    @elseif($token->expires_at && $token->expires_at->isFuture())
                                        <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">Expiring {{ $token->expires_at->diffForHumans() }}</span>
                                    @else
                                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if(!$token->revoked_at)
                                        <button wire:click="revokeToken('{{ $token->id }}')"
                                                wire:confirm="Revoke this token? It will stop working immediately."
                                                class="text-xs text-red-600 hover:text-red-800">
                                            Revoke
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">No tokens yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    {{-- Channels Tab --}}
    @elseif($activeTab === 'channels')
        @php
            $telegramChannels = $channels->filter(fn($c) => $c->channel_type->value === 'telegram');
            $activeToken = $tokens->first(fn($t) => !$t->revoked_at && (!$t->expires_at || $t->expires_at->isFuture()));
            $webhookBase = url('/api/chatbot/telegram');
        @endphp

        <div class="space-y-6">
            {{-- Telegram Section --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Telegram</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Connect a Telegram bot to this chatbot. Each bot token maps to one chatbot.</p>
                    </div>
                    @if(!$showTelegramForm)
                        <button wire:click="startTelegramEdit"
                                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                            Add Telegram Bot
                        </button>
                    @endif
                </div>

                {{-- Add / Edit form --}}
                @if($showTelegramForm)
                    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 space-y-3">
                        <h4 class="text-sm font-semibold text-primary-900">
                            {{ $editingChannelId ? 'Edit Telegram Bot' : 'Add Telegram Bot' }}
                        </h4>

                        <x-form-input
                            wire:model="telegramBotToken"
                            label="Bot Token"
                            placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
                            hint="From @BotFather → /newbot → token"
                            :error="$errors->first('telegramBotToken')" />

                        <x-form-input
                            wire:model="telegramWebhookSecret"
                            label="Webhook Secret (optional)"
                            placeholder="Leave blank to skip verification"
                            hint="A secret string Telegram will send in X-Telegram-Bot-Api-Secret-Token header." />

                        <div class="flex gap-2 pt-1">
                            <button wire:click="saveTelegramChannel"
                                    class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                                Save
                            </button>
                            <button wire:click="cancelTelegramForm"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Existing Telegram channels --}}
                @if($telegramChannels->isNotEmpty())
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Bot Token (masked)</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Webhook URL</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                    <th class="px-4 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach($telegramChannels as $channel)
                                    @php
                                        $rawToken = $channel->config['bot_token'] ?? '';
                                        $maskedToken = strlen($rawToken) > 12
                                            ? substr($rawToken, 0, 10) . '...' . substr($rawToken, -4)
                                            : '—';
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-sm text-gray-700">{{ $maskedToken }}</td>
                                        <td class="px-4 py-3">
                                            @if($activeToken)
                                                <code class="break-all rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                                                    {{ $webhookBase }}/{{ $activeToken->token_prefix }}
                                                </code>
                                            @else
                                                <span class="text-xs text-amber-600">Generate an API token first</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                                {{ $channel->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                                {{ $channel->is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right space-x-3">
                                            <button wire:click="startTelegramEdit('{{ $channel->id }}')"
                                                    class="text-xs text-primary-600 hover:text-primary-800">Edit</button>
                                            <button wire:click="toggleTelegramChannel('{{ $channel->id }}')"
                                                    class="text-xs text-gray-500 hover:text-gray-700">
                                                {{ $channel->is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                            <button wire:click="deleteTelegramChannel('{{ $channel->id }}')"
                                                    wire:confirm="Remove this Telegram channel?"
                                                    class="text-xs text-red-600 hover:text-red-800">Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif(!$showTelegramForm)
                    <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-400">No Telegram bots connected yet.</p>
                @endif

                {{-- Webhook registration instructions --}}
                @if($activeToken && $telegramChannels->isNotEmpty())
                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 space-y-2">
                        <p class="text-xs font-semibold text-blue-800">Register webhook with Telegram</p>
                        <p class="text-xs text-blue-700">Run this once after saving your bot token (replace <code class="font-mono">BOT_TOKEN</code>):</p>
                        <pre class="overflow-x-auto rounded bg-blue-900 px-3 py-2 text-xs text-blue-100 font-mono leading-relaxed">curl -X POST "https://api.telegram.org/botBOT_TOKEN/setWebhook" \
     -H "Content-Type: application/json" \
     -d '{"url": "{{ $webhookBase }}/{{ $activeToken->token_prefix }}"}'</pre>
                    </div>
                @endif
            </div>
        </div>

    {{-- Widget Tab --}}
    @elseif($activeTab === 'widget')
        <div class="space-y-6">
            {{-- Embed snippet --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900">Embed Snippet</h3>
                <p class="text-sm text-gray-500">Add this snippet before <code class="rounded bg-gray-100 px-1">&lt;/body&gt;</code> on any page where you want the chatbot to appear.</p>

                @php
                    $activeToken = $tokens->first(fn($t) => !$t->revoked_at && (!$t->expires_at || $t->expires_at->isFuture()));
                    $snippet = $activeToken
                        ? '<script src="' . url('/widget/chatbot.js') . '"' . "\n" . '        data-token="' . $activeToken->token_prefix . '..."' . "\n" . '        data-api-base="' . url('/') . '">' . "\n" . '</script>'
                        : '<!-- Generate an API token first (Tokens tab) -->';
                @endphp

                <div x-data="{ copied: false }">
                    <div class="relative">
                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-green-400 font-mono leading-relaxed">{{ $snippet }}</pre>
                    </div>
                    @if($activeToken)
                        <button onclick="navigator.clipboard.writeText({{ json_encode($snippet) }})"
                                class="mt-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Copy Snippet
                        </button>
                    @endif
                </div>
            </div>

            {{-- Widget config --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900">Widget Configuration</h3>
                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Position</dt>
                        <dd class="mt-1 text-sm text-gray-700">{{ $chatbot->widget_config['position'] ?? 'bottom-right' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Theme Color</dt>
                        <dd class="mt-1 flex items-center gap-2">
                            @php $color = $chatbot->widget_config['theme_color'] ?? '#6366f1'; @endphp
                            <span class="inline-block h-4 w-4 rounded-full border border-gray-200" style="background: {{ $color }}"></span>
                            <span class="text-sm font-mono text-gray-700">{{ $color }}</span>
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-gray-400">Edit theme_color and position via the API or configuration tab (advanced).</p>
            </div>
        </div>

    {{-- Conversations Tab --}}
    @elseif($activeTab === 'conversations')
        <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500">View all conversation sessions for this chatbot.</p>
                <a href="{{ route('chatbots.conversations', $chatbot) }}"
                   class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                    View Conversations
                </a>
            </div>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-4">
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sessions</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($sessionsCount) }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Messages</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($messagesCount) }}</dd>
                </div>
            </dl>
        </div>

    {{-- Knowledge Base Tab --}}
    @elseif($activeTab === 'knowledge')
        <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-900">Knowledge Base</p>
                    <p class="text-sm text-gray-500">Upload documents and URLs to enable RAG-powered answers.</p>
                </div>
                <a href="{{ route('chatbots.knowledge', $chatbot) }}"
                   class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                    Manage Knowledge Base
                </a>
            </div>
            @php
                $kbSourceCount = $chatbot->knowledgeSources()->where('status', 'ready')->count();
                $kbChunkCount = $chatbot->kbChunks()->count();
            @endphp
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-4">
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Ready Sources</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ $kbSourceCount }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wider">Indexed Chunks</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($kbChunkCount) }}</dd>
                </div>
            </dl>
            @if($kbSourceCount === 0)
                <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    No ready knowledge sources yet. Add documents or URLs so the chatbot can answer questions from your content.
                </p>
            @endif
        </div>
    @elseif($activeTab === 'analytics')
        {{-- Analytics Tab --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-900">Analytics</p>
                    <p class="text-sm text-gray-500">Containment rate, confidence trends, and unanswered questions.</p>
                </div>
                <a href="{{ route('chatbots.analytics', $chatbot) }}"
                   class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                    Open Analytics
                </a>
            </div>
        </div>
    @endif
</div>
