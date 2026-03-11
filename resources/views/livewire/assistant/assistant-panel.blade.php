<div
    x-data="{
        open: false,
        inputText: '',
        pendingMessage: null,
        sending: false,
        userScrolled: false,
        panelWidth: parseInt(localStorage.getItem('assistant-panel-width')) || 420,
        resizing: false,
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

            // Scroll to bottom when panel opens
            this.$watch('open', (val) => {
                if (val) {
                    this.userScrolled = false;
                    this.scrollToBottom();
                }
            });

            // Attach scroll listener + MutationObserver for auto-scroll
            this.$nextTick(() => {
                const container = this.$refs.messagesContainer;
                if (!container) return;

                // Detect when user manually scrolls up
                container.addEventListener('scroll', () => {
                    const distFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                    this.userScrolled = distFromBottom > 100;
                });

                // Auto-scroll on any content change (new messages or streaming tokens)
                new MutationObserver(() => this.scrollIfNeeded())
                    .observe(container, { childList: true, subtree: true });
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
        scrollIfNeeded() {
            if (!this.userScrolled) this.scrollToBottom();
        },
        async send() {
            const text = this.inputText.trim();
            if (!text || this.sending) return;

            this.inputText = '';
            this.sending = true;
            this.userScrolled = false;
            this.scrollToBottom();

            // Reset textarea height
            if (this.$refs.messageInput) {
                this.$refs.messageInput.style.height = 'auto';
            }

            // sendMessage() dispatches an async job and returns immediately —
            // no long HTTP wait. The pending bubble appears in messages[] and
            // wire:poll fills it in when the job completes.
            try {
                await $wire.sendMessage(text);
            } finally {
                this.sending = false;
                this.scrollToBottom();
            }
        },
        quickSend(text) {
            this.inputText = text;
            this.send();
        },
        startResize(e) {
            this.resizing = true;
            const startX = e.clientX;
            const startWidth = this.panelWidth;
            const onMove = (ev) => {
                const delta = startX - ev.clientX;
                this.panelWidth = Math.max(320, Math.min(startWidth + delta, window.innerWidth * 0.8));
            };
            const onUp = () => {
                this.resizing = false;
                localStorage.setItem('assistant-panel-width', this.panelWidth);
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
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
        class="fixed inset-y-0 right-0 flex flex-col border-l border-gray-200 bg-white shadow-2xl"
        :style="'width: ' + (window.innerWidth < 640 ? '100%' : panelWidth + 'px')"
    >
        {{-- Resize Handle --}}
        <div
            x-on:mousedown.prevent="startResize($event)"
            class="absolute inset-y-0 left-0 z-10 w-1.5 cursor-col-resize transition-colors hover:bg-indigo-400"
            :class="resizing ? 'bg-indigo-500' : 'bg-transparent'"
        ></div>

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
                    <p class="text-sm font-medium text-gray-500">FleetQ Assistant</p>
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

            {{-- Poll for async job completion every 3s when a message is pending --}}
            @if($pendingMessageId)
                <div wire:poll.3000ms="pollPendingMessage" class="hidden"></div>
            @endif

            {{-- Server-side messages --}}
            @foreach($messages as $msg)
                @if($msg['role'] === 'assistant' && ($msg['pending'] ?? false))
                    {{-- Thinking bubble — replaced by pollPendingMessage when job completes --}}
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
                @else
                    <div class="{{ $msg['role'] === 'user' ? 'flex justify-end' : 'flex justify-start' }}">
                        <div class="{{ $msg['role'] === 'user'
                            ? 'max-w-[85%] rounded-2xl rounded-br-md bg-indigo-600 px-4 py-2.5 text-white'
                            : 'max-w-[85%] rounded-2xl rounded-bl-md bg-gray-100 px-4 py-2.5 text-gray-900' }}">
                            @if($msg['role'] === 'assistant')
                                <div class="assistant-response prose prose-sm max-w-none">
                                    @if(($msg['content'] ?? '') === '')
                                        <span class="text-gray-400 text-xs italic">No response from agent. Check agent authentication and configuration.</span>
                                    @else
                                        {!! \Illuminate\Support\Str::markdown($msg['content'] ?? '') !!}
                                    @endif