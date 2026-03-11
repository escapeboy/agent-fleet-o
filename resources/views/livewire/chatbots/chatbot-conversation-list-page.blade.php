<div>
    {{-- Back link --}}
    <div class="mb-4">
        <a href="{{ route('chatbots.show', $chatbot) }}" class="text-sm text-primary-600 hover:text-primary-800">
            &larr; Back to {{ $chatbot->name }}
        </a>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <select wire:model.live="channel" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="">All Channels</option>
            <option value="web_widget">Web Widget</option>
            <option value="api">API</option>
            <option value="telegram">Telegram</option>
            <option value="slack">Slack</option>
        </select>
        <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="From date" />
        <input wire:model.live="dateTo" type="date" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="To date" />
    </div>

    {{-- Sessions table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Started</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channel</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Messages</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Activity</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">IP</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($sessions as $session)
                    <tr class="hover:bg-gray-50 cursor-pointer" wire:click="openSession('{{ $session->id }}')">
                        <td class="px-6 py-3 text-sm text-gray-900">{{ $session->started_at->format('M j, Y H:i') }}</td>
                        <td class="px-6 py-3">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">{{ $session->channel }}</span>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-700">{{ $session->messages_count }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $session->last_activity_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-6 py-3 text-sm font-mono text-gray-500 text-xs">{{ $session->ip_address ?? '—' }}</td>
                        <td class="px-6 py-3 text-right">
                            <svg class="inline h-4 w-4 text-gray-400 {{ $openSessionId === $session->id ? 'rotate-90' : '' }} transition-transform"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </td>
                    </tr>

                    {{-- Conversation transcript inline --}}
                    @if($openSessionId === $session->id && $openSession)
                        <tr>
                            <td colspan="6" class="bg-gray-50 px-6 py-4">
                                <div class="max-h-80 overflow-y-auto space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                                    @forelse($openSession->messages as $msg)
                                        <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                            <div class="max-w-xl rounded-xl px-4 py-2 text-sm {{ $msg->role === 'user' ? 'bg-primary-100 text-primary-900' : 'bg-gray-100 text-gray-800' }}">
                                                @if($msg->was_escalated)
                                                    <span class="mb-1 inline-flex items-center rounded-full bg-yellow-100 px-1.5 py-0.5 text-xs font-medium text-yellow-700">Escalated</span>
                                                @endif
                                                <p class="whitespace-pre-wrap">{{ $msg->content ?? $msg->draft_content }}</p>
                                                @if($msg->confidence)
                                                    <p class="mt-1 text-xs opacity-60">Confidence: {{ number_format((float)$msg->confidence * 100, 0) }}%</p>
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-gray-400 text-center">No messages in this session.</p>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400">No conversations yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $sessions->links() }}
    </div>
</div>
