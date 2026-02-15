<div
    x-data="{
        open: false,
        inputText: '',
        pendingMessage: null,
        sending: false,
        init() {
            // Watch for Livewire navigation to detect page context
            document.addEventListener('livewire:navigated', () => {
                const path = window.location.pathname;
                const match = path.match(/\/(experiments|projects|agents|crews|workflows|skills|tools|credentials)\/([^\/]+)$/);
                if (match) {
                    $wire.setContext(match[1].replace(/s$/, ''), match[2]);
                } else {
                    $wire.setContext('', '');
                }
            });

            // Keyboard shortcut: Cmd+K / Ctrl+K
            document.addEventListener('keydown', (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                    e.preventDefault();
                    this.open = !this.open;
                    if (this.open) {
                        this.$nextTick(() => this.$refs.messageInput?.focus());
                    }
                }
            });
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const container = this.$refs.messagesContainer;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },
        async send() {
            const text = this.inputText.trim();
            if (!text || this.sending) return;

            this.inputText = '';
            this.sending = true;
            this.pendingMessage = text;
            this.scrollToBottom();

            // Reset textarea height
            if (this.$refs.messageInput) {
                this.$refs.messageInput.style.height = 'auto';
            }

            try {
                await $wire.sendMessage(text);
            } finally {
                this.pendingMessage = null;
                this.sending = false;
                this.scrollToBottom();
            }
        },
        quickSend(text) {
            this.inputText = text;
            this.send();
        }
    }"
    class="relative z-50"
>
    {{-- Toggle Button (FAB) --}}
    <button
        x-on:click="open = !open; if (open) $nextTick(() => $refs.messageInput?.focus())"
        x-show="!open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="scale-75 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        class="fixed bottom-6 right-6 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        title="Open Assistant (Cmd+K)"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
        </svg>
    </button>

    {{-- Slide-out Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 flex w-full flex-col border-l border-gray-200 bg-white shadow-2xl sm:w-[420px]"
    >
        {{-- Panel Header --}}
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-indigo-600">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                </svg>
                <h2 class="text-sm font-semibold text-gray-900">Assistant</h2>
            </div>
            <div class="flex items-center gap-1">
                {{-- History Toggle --}}
                <button
                    wire:click="toggleHistory"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="Conversation History"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </button>
                {{-- New Conversation --}}
                <button
                    wire:click="newConversation"
                    x-on:click="pendingMessage = null; sending = false"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="New Conversation"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </button>
                {{-- Close --}}
                <button
                    x-on:click="open = false"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="Close (Esc)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- Conversation History Sidebar --}}
        @if($showHistory)
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
                <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Recent Conversations</h3>
                <div class="max-h-60 space-y-1 overflow-y-auto">
                    @forelse($conversations as $conv)
                        <button
                            wire:click="loadConversation('{{ $conv['id'] }}')"
                            class="block w-full rounded px-2 py-1.5 text-left text-sm transition-colors hover:bg-gray-200 {{ $conversationId === $conv['id'] ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700' }}"
                        >
                            <div class="truncate font-medium">{{ $conv['title'] }}</div>
                            <div class="text-xs text-gray-400">{{ $conv['last_message_at'] }}</div>
                        </button>
                    @empty
                        <p class="py-2 text-center text-xs text-gray-400">No conversations yet</p>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- Messages Area --}}
        <div
            x-ref="messagesContainer"
            class="flex-1 space-y-4 overflow-y-auto px-4 py-4"
        >
            @if(empty($messages))
                <div x-show="!pendingMessage" class="flex h-full flex-col items-center justify-center text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="mb-3 h-12 w-12 text-gray-300">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                    </svg>
                    <p class="text-sm font-medium text-gray-500">Agent Fleet Assistant</p>
                    <p class="mt-1 text-xs text-gray-400">Ask me anything about your projects,<br>experiments, agents, and more.</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <button x-on:click="quickSend('List my recent experiments')" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600 transition-colors hover:border-indigo-300 hover:text-indigo-600">
                            List experiments
                        </button>
                        <button x-on:click="quickSend('Show dashboard KPIs')" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600 transition-colors hover:border-indigo-300 hover:text-indigo-600">
                            Dashboard KPIs
                        </button>
                        <button x-on:click="quickSend('What is my budget status?')" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600 transition-colors hover:border-indigo-300 hover:text-indigo-600">
                            Budget status
                        </button>
                    </div>
                </div>
            @endif

            {{-- Server-side messages --}}
            @foreach($messages as $msg)
                <div class="{{ $msg['role'] === 'user' ? 'flex justify-end' : 'flex justify-start' }}">
                    <div class="{{ $msg['role'] === 'user'
                        ? 'max-w-[85%] rounded-2xl rounded-br-md bg-indigo-600 px-4 py-2.5 text-white'
                        : 'max-w-[85%] rounded-2xl rounded-bl-md bg-gray-100 px-4 py-2.5 text-gray-900' }}">
                        @if($msg['role'] === 'assistant')
                            <div class="assistant-response prose prose-sm max-w-none">
                                {!! \Illuminate\Support\Str::markdown($msg['content']) !!}
                            </div>
                            @if(!empty($msg['tool_calls_count']))
                                <div class="mt-2 flex items-center gap-1 text-xs text-gray-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-3 w-3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
                                    </svg>
                                    {{ $msg['tool_calls_count'] }} tool call{{ $msg['tool_calls_count'] > 1 ? 's' : '' }}
                                </div>
                            @endif
                        @else
                            <p class="text-sm whitespace-pre-wrap">{{ $msg['content'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Optimistic user message (shown immediately, before server responds) --}}
            <template x-if="pendingMessage">
                <div class="flex justify-end">
                    <div class="max-w-[85%] rounded-2xl rounded-br-md bg-indigo-600 px-4 py-2.5 text-white">
                        <p class="text-sm whitespace-pre-wrap" x-text="pendingMessage"></p>
                    </div>
                </div>
            </template>

            {{-- Thinking indicator --}}
            <template x-if="sending">
                <div class="flex justify-start">
                    <div class="max-w-[85%] rounded-2xl rounded-bl-md bg-gray-100 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="flex gap-1">
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0ms"></span>
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 150ms"></span>
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 300ms"></span>
                            </div>
                            <span class="text-xs text-gray-500">Thinking...</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Input Area --}}
        <div class="border-t border-gray-200 px-4 py-3">
            {{-- Model Selector --}}
            <div class="mb-2 flex items-center gap-2">
                <select
                    wire:model.live="selectedProvider"
                    class="h-7 rounded-lg border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                    :disabled="sending"
                >
                    @foreach($providers as $key => $provider)
                        <option value="{{ $key }}">{{ $provider['name'] }}</option>
                    @endforeach
                </select>
                <select
                    wire:model="selectedModel"
                    class="h-7 flex-1 rounded-lg border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400"
                    :disabled="sending"
                >
                    @if(isset($providers[$selectedProvider]['models']))
                        @foreach($providers[$selectedProvider]['models'] as $modelKey => $modelConfig)
                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                        @endforeach
                    @endif
                </select>
                @if(!empty($providers[$selectedProvider]['local']))
                    <span class="inline-flex items-center rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700">Local</span>
                @endif
            </div>
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <textarea
                        x-ref="messageInput"
                        x-model="inputText"
                        placeholder="Ask anything... (Cmd+K to toggle)"
                        rows="1"
                        class="block w-full resize-none rounded-xl border border-gray-300 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        x-on:keydown.enter.prevent="if (!$event.shiftKey) send()"
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                        :disabled="sending"
                    ></textarea>
                </div>
                <button
                    type="button"
                    x-on:click="send()"
                    :disabled="sending || !inputText.trim()"
                    class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                </button>
            </div>
            <p class="mt-1.5 text-center text-[10px] text-gray-400">
                Press Enter to send, Shift+Enter for new line
            </p>
        </div>
    </div>
</div>
