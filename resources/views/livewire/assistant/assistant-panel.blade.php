<div
    x-data="{
        open: false,
        inputText: '',
        pendingMessage: null,
        sending: false,
        messageQueue: [],
        userScrolled: false,
        graceMessage: null,
        graceSecondsLeft: 3,
        graceTimers: [],
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

            // Open panel and pre-fill message from browser event
            window.addEventListener('open-assistant', (e) => {
                this.open = true;
                if (e.detail?.message) {
                    this.$nextTick(() => {
                        $wire.set('inputText', e.detail.message);
                        this.$refs.messageInput?.focus();
                    });
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

            // Process next queued message when the pending job clears
            this.$watch(() => $wire.pendingMessageId, (newVal) => {
                if (newVal === '' && this.messageQueue.length > 0) {
                    const next = this.messageQueue.shift();
                    this._dispatch(next);
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

            // WebMCP: expose assistant chat to browser AI agents
            if (window.FleetQWebMcp?.isAvailable()) {
                window.FleetQWebMcp.registerTool({
                    name: 'assistant_send_message',
                    description: 'Send a message to the FleetQ AI assistant and get a response. The assistant has full access to platform tools and can perform actions on your behalf.',
                    inputSchema: {
                        type: 'object',
                        properties: {
                            message: { type: 'string', description: 'The message to send to the FleetQ assistant' }
                        },
                        required: ['message']
                    },
                    annotations: { readOnlyHint: false },
                    execute: async ({ message }) => {
                        // Open the panel if closed
                        this.open = true;
                        await this.$nextTick();

                        // Send message through the normal flow
                        this.inputText = message;
                        await this.send();

                        // Wait for the async job to complete (poll pendingMessageId)
                        return new Promise((resolve) => {
                            const maxWait = 60000;
                            const start = Date.now();
                            const check = setInterval(async () => {
                                const pending = $wire.pendingMessageId;
                                if (pending === '' || Date.now() - start > maxWait) {
                                    clearInterval(check);
                                    const msgs = $wire.messages;
                                    const last = msgs?.[msgs.length - 1];
                                    resolve({
                                        content: [{ type: 'text', text: last?.content ?? 'No response within timeout.' }]
                                    });
                                }
                            }, 1500);
                        });
                    }
                });
            }
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
            if (!text) return;

            this.inputText = '';
            if (this.$refs.messageInput) {
                this.$refs.messageInput.style.height = 'auto';
            }

            // If a job is already in progress, queue the message
            if (this.sending || $wire.pendingMessageId !== '') {
                this.messageQueue.push(text);
                return;
            }

            // If another grace window is active, clear it and start a new one
            if (this.graceMessage !== null) {
                this.cancelGrace();
            }

            // Start grace window — user has 3 seconds to cancel before dispatch
            this.graceMessage = text;
            this.graceSecondsLeft = 3;

            const countdown = setInterval(() => {
                if (this.graceSecondsLeft > 0) this.graceSecondsLeft--;
            }, 1000);

            const dispatch = setTimeout(async () => {
                clearInterval(countdown);
                const msg = this.graceMessage;
                this.graceMessage = null;
                this.graceTimers = [];
                if (msg) await this._dispatch(msg);
            }, 3000);

            this.graceTimers = [countdown, dispatch];
        },
        cancelGrace() {
            this.graceTimers.forEach(t => clearTimeout(t));
            this.graceTimers = [];
            this.graceMessage = null;
            this.graceSecondsLeft = 3;
        },
        async _dispatch(text) {
            this.sending = true;
            this.userScrolled = false;
            this.scrollToBottom();

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
                    x-on:click="pendingMessage = null; sending = false; cancelGrace()"
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
            {{-- Grace window: pending user message with countdown and cancel --}}
            <template x-if="graceMessage !== null">
                <div class="flex justify-end">
                    <div class="max-w-[85%]">
                        <div class="rounded-2xl rounded-br-md bg-indigo-400 px-4 py-2.5 text-white opacity-75">
                            <p class="text-sm whitespace-pre-wrap" x-text="graceMessage"></p>
                        </div>
                        <div class="mt-1 flex items-center justify-end gap-2">
                            <span class="text-[11px] text-gray-400" x-text="'Sending in ' + graceSecondsLeft + 's…'"></span>
                            <button
                                type="button"
                                x-on:click="cancelGrace()"
                                class="text-[11px] font-medium text-red-500 hover:text-red-700"
                            >Cancel</button>
                        </div>
                    </div>
                </div>
            </template>

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
                @endif
            @endforeach
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
            {{-- Speech input wrapper: inline x-data avoids alpine:init timing race with pwa-features.js --}}
            <div x-data="{
                    isSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
                    isListening: false,
                    transcript: '',
                    recognition: null,
                    init() {
                        if (!this.isSupported) return;
                        const API = window.SpeechRecognition || window.webkitSpeechRecognition;
                        this.recognition = new API();
                        this.recognition.continuous = false;
                        this.recognition.interimResults = true;
                        this.recognition.lang = document.documentElement.lang || 'en-US';
                        this.recognition.addEventListener('result', (e) => {
                            let final = '';
                            for (let i = e.resultIndex; i < e.results.length; i++) {
                                if (e.results[i].isFinal) final += e.results[i][0].transcript;
                            }
                            if (final) this.transcript = final;
                        });
                        this.recognition.addEventListener('end', () => {
                            this.isListening = false;
                            if (this.transcript) {
                                this.$dispatch('speech-result', { text: this.transcript });
                                this.transcript = '';
                            }
                        });
                        this.recognition.addEventListener('error', (e) => {
                            this.isListening = false;
                            if (e.error !== 'aborted') console.warn('[FleetQ] Speech error:', e.error);
                        });
                    },
                    toggle() {
                        if (!this.isSupported) return;
                        if (this.isListening) { this.recognition.stop(); }
                        else { this.transcript = ''; this.recognition.start(); this.isListening = true; }
                    }
                }"
                x-on:speech-result.window="inputText = $event.detail.text">
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <textarea
                            x-ref="messageInput"
                            x-model="inputText"
                            placeholder="Ask anything... (Cmd+K to toggle)"
                            rows="1"
                            autocomplete="off"
                            data-1p-ignore
                            data-lpignore="true"
                            class="block w-full resize-none rounded-xl border border-gray-300 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                            x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                        ></textarea>
                    </div>
                    {{-- Mic button — only rendered when SpeechRecognition is supported --}}
                    <button
                        x-show="isSupported"
                        type="button"
                        x-on:click="toggle()"
                        :title="isListening ? 'Stop recording' : 'Dictate message'"
                        :class="isListening ? 'bg-red-100 text-red-600 ring-2 ring-red-400' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                        </svg>
                    </button>
                    <div class="relative flex-shrink-0">
                        <button
                            type="button"
                            x-on:click="send()"
                            :disabled="!inputText.trim()"
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{-- Spinner when job is active --}}
                            <svg x-show="sending || $wire.pendingMessageId !== ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="h-5 w-5 animate-spin">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{-- Send arrow when idle --}}
                            <svg x-show="!sending && $wire.pendingMessageId === ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                        </button>
                        {{-- Queue count badge --}}
                        <span
                            x-show="messageQueue.length > 0"
                            x-text="messageQueue.length"
                            class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-amber-400 text-[10px] font-bold text-white"
                        ></span>
                    </div>
                </div>
                <p class="mt-1.5 text-center text-[10px] text-gray-400">
                    Press Enter to send, Shift+Enter for new line
                    <span x-show="isListening" class="ml-2 text-red-500">● Recording…</span>
                </p>
            </div>
        </div>
    </div>
</div>