<div wire:poll.10s>
    @php
        // Keys in the target JSONB that contain credentials — never display these
        $sensitiveTargetKeys = ['access_token', 'secret', 'password', 'token', 'api_key', 'api_url', 'headers', 'phone_number'];
    @endphp

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Recipient</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Delivery</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Risk</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Sent</th>
                    <th class="w-6 px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($proposals as $proposal)
                    @php
                        $isExpanded = $expandedProposalId === $proposal->id;
                        $lastAction = $proposal->outboundActions->last();
                        $riskColor = $proposal->risk_score > 0.7 ? 'text-red-600' : ($proposal->risk_score > 0.4 ? 'text-yellow-600' : 'text-green-600');

                        // Canonical recipient field per channel (no sensitive data)
                        $target = $proposal->target ?? [];
                        $recipient = match($proposal->channel->value) {
                            'email'           => $target['email'] ?? null,
                            'telegram'        => $target['chat_id'] ?? $target['id'] ?? null,
                            'slack'           => $target['channel'] ?? null,
                            'webhook'         => isset($target['url']) ? parse_url($target['url'], PHP_URL_HOST) : null,
                            'discord'         => isset($target['webhook_url']) ? parse_url($target['webhook_url'], PHP_URL_HOST) : null,
                            'teams'           => isset($target['webhook_url']) ? parse_url($target['webhook_url'], PHP_URL_HOST) : null,
                            'whatsapp'        => $target['phone'] ?? $target['to'] ?? null,
                            'google_chat'     => isset($target['webhook_url']) ? parse_url($target['webhook_url'], PHP_URL_HOST) : null,
                            'signal_protocol' => $target['recipient'] ?? null,
                            'matrix'          => $target['room_id'] ?? null,
                            default           => null,
                        };
                        $recipient ??= '—';

                        // Channel icon
                        $channelIcon = match($proposal->channel->value) {
                            'email'           => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
                            'telegram'        => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>',
                            'slack'           => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>',
                            'webhook'         => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>',
                            'discord'         => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>',
                            default           => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>',
                        };
                    @endphp

                    {{-- Main clickable row --}}
                    <tr wire:click="toggleProposal('{{ $proposal->id }}')"
                        class="cursor-pointer transition-colors {{ $isExpanded ? 'bg-blue-50' : 'hover:bg-gray-50' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-400">{!! $channelIcon !!}</span>
                                <span class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $proposal->channel->value)) }}</span>
                                @if($proposal->batch_id)
                                    <span class="rounded bg-gray-100 px-1 py-0.5 text-[10px] text-gray-500">Batch {{ ($proposal->batch_index ?? 0) + 1 }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="max-w-[180px] truncate px-4 py-3 text-sm text-gray-600" title="{{ $recipient }}">
                            {{ $recipient }}
                        </td>
                        <td class="px-4 py-3">
                            <x-status-badge :status="$proposal->status->value" />
                        </td>
                        <td class="px-4 py-3">
                            @if($lastAction)
                                <div class="flex items-center gap-1.5">
                                    <x-status-badge :status="$lastAction->status->value" />
                                    @if($lastAction->retry_count > 0)
                                        <span class="rounded-full bg-yellow-100 px-1.5 py-0.5 text-[10px] font-medium text-yellow-700">{{ $lastAction->retry_count }}×</span>
                                    @endif
                                    @if(isset($lastAction->response['simulated']) && $lastAction->response['simulated'])
                                        <span class="rounded-full bg-purple-100 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">DRY RUN</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm font-medium {{ $riskColor }}">{{ number_format($proposal->risk_score, 2) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            @if($lastAction?->sent_at)
                                <span title="{{ $lastAction->sent_at->format('Y-m-d H:i:s') }}">{{ $lastAction->sent_at->diffForHumans() }}</span>
                            @else
                                <span title="{{ $proposal->created_at->format('Y-m-d H:i:s') }}">{{ $proposal->created_at->diffForHumans() }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <svg class="h-3.5 w-3.5 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </td>
                    </tr>

                    {{-- Expanded detail row --}}
                    @if($isExpanded)
                        @php
                            $content = $proposal->content ?? [];
                            $isEmailChannel = $proposal->channel->value === 'email';
                            $isWebhookChannel = $proposal->channel->value === 'webhook';
                            $messageText = $content['body'] ?? $content['text'] ?? null;
                            $subject = $content['subject'] ?? null;
                            $isExperimentSummary = ($content['type'] ?? null) === 'experiment_summary';

                            // Safe target fields: omit credential keys
                            $safeTarget = array_filter(
                                $target,
                                fn($key) => !in_array($key, $sensitiveTargetKeys),
                                ARRAY_FILTER_USE_KEY
                            );
                        @endphp
                        <tr class="bg-blue-50">
                            <td colspan="7" class="px-4 pb-5 pt-0">
                                <div class="rounded-lg border border-blue-100 bg-white p-4 shadow-sm">
                                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                                        {{-- Left: Recipient + Message Content --}}
                                        <div class="space-y-4">

                                            {{-- Recipient details (credential-safe) --}}
                                            <div>
                                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Recipient</h4>
                                                <div class="rounded-lg bg-gray-50 p-3">
                                                    @forelse($safeTarget as $key => $value)
                                                        <div class="flex gap-2 py-0.5">
                                                            <span class="w-32 shrink-0 text-xs font-medium text-gray-500">{{ $key }}</span>
                                                            <span class="break-all text-xs text-gray-900">{{ is_string($value) ? $value : json_encode($value) }}</span>
                                                        </div>
                                                    @empty
                                                        <span class="text-xs text-gray-400">No recipient data.</span>
                                                    @endforelse
                                                </div>
                                            </div>

                                            {{-- Message content --}}
                                            <div>
                                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Message Sent</h4>
                                                @if($isExperimentSummary)
                                                    <div class="rounded-lg bg-amber-50 p-3">
                                                        <p class="text-xs text-amber-700">Auto-generated experiment summary email. Content is built from the experiment results at send time.</p>
                                                    </div>
                                                @elseif($isEmailChannel && ($subject || $messageText))
                                                    <div class="rounded-lg border border-gray-200 bg-white">
                                                        @if($subject)
                                                            <div class="border-b border-gray-100 px-3 py-2">
                                                                <span class="text-xs font-medium text-gray-500">Subject: </span>
                                                                <span class="text-xs text-gray-900">{{ $subject }}</span>
                                                            </div>
                                                        @endif
                                                        <div class="p-3">
                                                            <p class="whitespace-pre-wrap text-xs text-gray-700">{{ $messageText ?? '(no body)' }}</p>
                                                        </div>
                                                    </div>
                                                @elseif(!$isWebhookChannel && $messageText)
                                                    <div class="rounded-lg bg-gray-50 p-3">
                                                        <p class="whitespace-pre-wrap text-xs text-gray-700">{{ $messageText }}</p>
                                                    </div>
                                                @elseif(!empty($content))
                                                    {{-- Webhook or fallback: JSON viewer --}}
                                                    <div x-data="{ expanded: false, copied: false }">
                                                        <pre x-ref="contentJson"
                                                             :class="expanded ? 'max-h-none' : 'max-h-40'"
                                                             class="overflow-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-green-400 transition-all">{{ json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        <div class="mt-1 flex gap-3">
                                                            <button x-on:click="expanded = !expanded" class="text-xs text-gray-500 hover:text-gray-700">
                                                                <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                                                            </button>
                                                            <button x-on:click="navigator.clipboard.writeText($refs.contentJson.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                                                                    class="text-xs text-gray-500 hover:text-gray-700">
                                                                <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @else
                                                    <p class="text-xs text-gray-400">No message content.</p>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Right: Delivery Action --}}
                                        <div>
                                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                Delivery
                                                @if($proposal->outboundActions->isNotEmpty())
                                                    <span class="ml-1 font-normal normal-case text-gray-400">({{ $proposal->outboundActions->count() }} attempt{{ $proposal->outboundActions->count() === 1 ? '' : 's' }})</span>
                                                @endif
                                            </h4>

                                            @if($proposal->outboundActions->isEmpty())
                                                <div class="rounded-lg bg-gray-50 p-4 text-center">
                                                    @php $pStatus = $proposal->status->value; @endphp
                                                    @if($pStatus === 'pending_approval')
                                                        <p class="text-xs text-yellow-600">Awaiting approval before delivery.</p>
                                                    @elseif($pStatus === 'rejected')
                                                        <p class="text-xs text-gray-500">Proposal was rejected — no delivery attempted.</p>
                                                    @elseif($pStatus === 'expired')
                                                        <p class="text-xs text-gray-500">Proposal expired — no delivery attempted.</p>
                                                    @elseif($pStatus === 'cancelled')
                                                        <p class="text-xs text-gray-500">Proposal was cancelled — no delivery attempted.</p>
                                                    @else
                                                        <p class="text-xs text-gray-400">Queued for delivery.</p>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="space-y-3">
                                                    @foreach($proposal->outboundActions as $actionIdx => $action)
                                                        @php
                                                            $dotColor = match($action->status->value) {
                                                                'sent'             => 'bg-green-500',
                                                                'failed', 'bounced' => 'bg-red-500',
                                                                'sending'          => 'bg-blue-500',
                                                                default            => 'bg-gray-400',
                                                            };
                                                        @endphp
                                                        <div class="relative pl-5" x-data="{ responseOpen: false, responseCopied: false }">
                                                            {{-- Timeline dot + connector line --}}
                                                            <div class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full {{ $dotColor }} ring-2 ring-white"></div>
                                                            @if(!$loop->last)
                                                                <div class="absolute left-[4px] top-4 h-full w-px bg-gray-200"></div>
                                                            @endif

                                                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                                                {{-- Status badges --}}
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <x-status-badge :status="$action->status->value" />
                                                                    @if($action->retry_count > 0)
                                                                        <span class="rounded-full bg-yellow-100 px-1.5 py-0.5 text-[10px] font-medium text-yellow-700">
                                                                            {{ $action->retry_count }} retr{{ $action->retry_count === 1 ? 'y' : 'ies' }}
                                                                        </span>
                                                                    @endif
                                                                    @if(isset($action->response['simulated']) && $action->response['simulated'])
                                                                        <span class="rounded-full bg-purple-100 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">DRY RUN</span>
                                                                    @endif
                                                                </div>

                                                                {{-- Delivery metadata --}}
                                                                <div class="mt-2 space-y-0.5">
                                                                    @if($action->sent_at)
                                                                        <div class="flex gap-2 text-xs">
                                                                            <span class="w-24 shrink-0 font-medium text-gray-400">Sent at</span>
                                                                            <span class="text-gray-700">{{ $action->sent_at->format('Y-m-d H:i:s') }}</span>
                                                                        </div>
                                                                    @endif
                                                                    @if($action->external_id)
                                                                        <div class="flex gap-2 text-xs">
                                                                            <span class="w-24 shrink-0 font-medium text-gray-400">Message ID</span>
                                                                            <span class="break-all font-mono text-gray-700">{{ $action->external_id }}</span>
                                                                        </div>
                                                                    @endif
                                                                </div>

                                                                {{-- Response JSON viewer --}}
                                                                @if($action->response)
                                                                    <div class="mt-2">
                                                                        <button x-on:click="responseOpen = !responseOpen"
                                                                                class="text-[10px] font-medium text-gray-400 hover:text-gray-600">
                                                                            <span x-text="responseOpen ? '▲ Hide response' : '▼ Show response'"></span>
                                                                        </button>
                                                                        <div x-show="responseOpen" x-cloak class="mt-2">
                                                                            <div class="relative">
                                                                                <button x-on:click="navigator.clipboard.writeText($refs.responseJson{{ $actionIdx }}.textContent); responseCopied = true; setTimeout(() => responseCopied = false, 2000)"
                                                                                        class="absolute right-2 top-2 z-10 rounded bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-300 hover:bg-gray-600">
                                                                                    <span x-text="responseCopied ? '✓' : 'Copy'"></span>
                                                                                </button>
                                                                                <pre x-ref="responseJson{{ $actionIdx }}"
                                                                                     class="max-h-48 overflow-auto rounded bg-gray-900 p-3 pr-14 font-mono text-xs text-green-400">{{ json_encode($action->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @else
                                                                    <p class="mt-1 text-[10px] text-gray-400">No response recorded.</p>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>

                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif

                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">No outbound proposals yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
