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

            // Pre-fill textarea with a prompt from the gallery
            window.addEventListener('use-prompt', (e) => {
                this.inputText = e.detail.text ?? '';
                this.$nextTick(() => this.$refs.messageInput?.focus());
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
        <i class="fa-regular fa-comment-dots text-xl"></i>
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
                <i class="fa-solid fa-wand-magic-sparkles text-lg text-indigo-600"></i>
                <h2 class="text-sm font-semibold text-gray-900">Assistant</h2>
                @if(!empty($compressionStats['total_compressions']))
                    <span
                        class="ml-1.5 inline-flex items-center gap-0.5 rounded-full bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-600"
                        title="Memory compressed {{ $compressionStats['total_compressions'] }}x ({{ $compressionStats['total_messages_compressed'] ?? 0 }} messages)"
                    >
                        <i class="fa-solid fa-database text-xs"></i>
                        {{ $compressionStats['total_compressions'] }}x
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-1">
                {{-- Review Conversation --}}
                @if($conversationId && count($messages) > 5 && auth()->user()?->currentTeam?->memberRole(auth()->user())?->value !== 'viewer')
                    <button
                        x-data="{ reviewing: false, reviewResult: null }"
                        x-on:click="
                            if (reviewing) return;
                            reviewing = true;
                            reviewResult = null;
                            fetch('/api/v1/assistant/conversations/{{ $conversationId }}/review', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                    'Accept': 'application/json',
                                }
                            })
                            .then(r => r.json())
                            .then(data => {
                                reviewing = false;
                                reviewResult = data.review ?? null;
                                if (reviewResult) {
                                    $dispatch('assistant-review-complete', { review: reviewResult });
                                }
                            })
                            .catch(() => { reviewing = false; })
                        "
                        :disabled="reviewing"
                        class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50"
                        title="Review Conversation Quality"
                    >
                        <i class="fa-solid fa-star text-base" :class="reviewing ? 'fa-spin' : ''"></i>
                    </button>
                @endif
                {{-- History Toggle --}}
                <button
                    wire:click="toggleHistory"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="Conversation History"
                >
                    <i class="fa-regular fa-clock text-base"></i>
                </button>
                {{-- New Conversation --}}
                <button
                    wire:click="newConversation"
                    x-on:click="pendingMessage = null; sending = false; cancelGrace()"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="New Conversation"
                >
                    <i class="fa-solid fa-plus text-base"></i>
                </button>
                {{-- Close --}}
                <button
                    x-on:click="open = false"
                    class="rounded p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    title="Close (Esc)"
                >
                    <i class="fa-solid fa-xmark text-base"></i>
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

        @if($contextType === 'selection' && $contextId !== '')
            @php
                $selection = json_decode($contextId, true) ?: [];
                $selectionKind = $selection['kind'] ?? 'item';
                $selectionCount = count($selection['ids'] ?? []);
            @endphp
            <div class="flex items-center justify-between border-b border-indigo-100 bg-indigo-50 px-4 py-2 text-xs">
                <div class="flex items-center gap-2 text-indigo-700">
                    <i class="fa-solid fa-layer-group"></i>
                    <span class="font-medium">Selection:</span>
                    <span>{{ $selectionCount }} {{ $selectionKind }}{{ $selectionCount === 1 ? '' : 's' }} — assistant will act on these</span>
                </div>
                <button
                    wire:click="applySelection('', [])"
                    class="text-indigo-500 transition-colors hover:text-indigo-700"
                    title="Clear selection"
                >
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        @endif

        {{-- Messages Area --}}
        <div
            x-ref="messagesContainer"
            class="flex-1 space-y-4 overflow-y-auto px-4 py-4"
        >
            @if(empty($messages))
                <div x-show="!pendingMessage" class="h-full overflow-y-auto">
                    {{-- Gallery heading --}}
                    <div class="mb-4 text-center">
                        <p class="text-sm font-medium text-gray-600">What would you like to do?</p>
                        <p class="mt-0.5 text-xs text-gray-400">Click any prompt to get started</p>
                    </div>

                    {{-- Categorized prompt gallery --}}
                    <div class="space-y-4">
                        @foreach($promptGallery as $category => $prompts)
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">{{ $category }}</p>
                                <div class="space-y-1.5">
                                    @foreach($prompts as $prompt)
                                        <button
                                            @click="$wire.usePrompt({{ json_encode($prompt) }})"
                                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-left text-sm text-gray-700 transition-colors hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
                                        >
                                            {{ $prompt }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Poll for streaming updates every 1.5s when a message is pending --}}
            @if($pendingMessageId)
                <div wire:poll.1500ms="pollPendingMessage" class="hidden"></div>
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
                    {{-- Streaming / thinking bubble --}}
                    <div class="flex justify-start">
                        <div class="max-w-[85%] rounded-2xl rounded-bl-md bg-gray-100 px-4 py-3">
                            @if(!empty($msg['tool_calls_in_progress']))
                                <div class="mb-2 flex flex-wrap items-center gap-1.5">
                                    @foreach($msg['tool_calls_in_progress'] as $toolName)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-600">
                                            <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                                            {{ $toolName }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($msg['content']) && ($msg['streaming'] ?? false))
                                <div class="assistant-response prose prose-sm max-w-none">
                                    {!! \Illuminate\Support\Str::markdown($msg['content']) !!}
                                </div>
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-indigo-400"></span>
                                    <span class="text-[10px] text-gray-400">Generating...</span>
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-1">
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0ms"></span>
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 150ms"></span>
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-gray-400" style="animation-delay: 300ms"></span>
                                    </div>
                                    <span class="text-xs text-gray-500">Thinking...</span>
                                </div>
                            @endif
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
                                @if(!empty($msg['a2ui_surfaces']))
                                    @foreach($msg['a2ui_surfaces'] as $surface)
                                        <div class="mt-3 -mx-1">
                                            <x-a2ui.surface
                                                :components="$surface['components'] ?? []"
                                                :data-model="$surface['dataModel'] ?? $surface['data_model'] ?? []"
                                            />
                                        </div>
                                    @endforeach
                                @endif
                                @if(!empty($msg['ui_artifacts']['items']))
                                    <div class="-mx-1 mt-2">
                                        @foreach($msg['ui_artifacts']['items'] as $artifactIndex => $artifactPayload)
                                            <x-assistant.artifacts.renderer
                                                :payload="$artifactPayload"
                                                :index="$artifactIndex"
                                                :messageId="$msg['id'] ?? null"
                                            />
                                        @endforeach
                                    </div>
                                @endif
                                @if(!empty($msg['citations']))
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <span class="font-medium text-gray-500">Sources:</span>
                                        @foreach($msg['citations'] as $c)
                                            <a href="{{ $c['url'] }}"
                                               target="_blank"
                                               rel="noopener"
                                               class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700 transition-colors hover:bg-indigo-100"
                                               title="{{ ucfirst($c['kind']) }} · {{ $c['id'] }}">
                                                <span class="text-[10px] text-indigo-500">[{{ $c['n'] }}]</span>
                                                <span class="max-w-[200px] truncate">{{ $c['title'] }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                                @if(!empty($msg['tool_calls_count']))
                                    <div class="mt-2 flex items-center gap-1 text-xs text-gray-400">
                                        <i class="fa-solid fa-screwdriver-wrench text-xs"></i>
                                        {{ $msg['tool_calls_count'] }} tool call{{ $msg['tool_calls_count'] > 1 ? 's' : '' }}
                                    </div>
                                @endif
                                @if(!empty($msg['mcp_app_uris']))
                                    @foreach($msg['mcp_app_uris'] as $mcpToolName => $mcpUri)
                                        @php $mcpHtml = \App\Mcp\Services\McpAppRegistry::htmlForUri($mcpUri); @endphp
                                        @if($mcpHtml)
                                            <div wire:ignore class="mt-3"
                                                 x-data="{
                                                    init() {
                                                        const encoded = this.$el.dataset.apphtml;
                                                        if (encoded) this.$refs.frame.srcdoc = atob(encoded);
                                                        const self = this;
                                                        self._msgHandler = function(e) {
                                                            if (!self.$refs.frame || e.source !== self.$refs.frame.contentWindow) return;
                                                            const msg = e.data;
                                                            if (!msg || msg.jsonrpc !== '2.0') return;
                                                            if (msg.method === 'ui/initialize' && msg.id !== undefined) {
                                                                e.source.postMessage({ jsonrpc: '2.0', id: msg.id, result: { protocolVersion: '2026-01-26', serverInfo: { name: 'fleetq-assistant', version: '1.0.0' }, capabilities: {} } }, '*');
                                                                return;
                                                            }
                                                            if (msg.method === 'ui/notifications/initialized') return;
                                                            if (msg.method === 'ui/update-model-context') return;
                                                            if (msg.method === 'tools/call' && msg.id !== undefined) {
                                                                const toolName = msg.params && msg.params.name;
                                                                const args = (msg.params && msg.params.arguments) || {};
                                                                if (!toolName) return;
                                                                self.$wire.mcpAppCallTool(toolName, args)
                                                                    .then(function(result) {
                                                                        if (self.$refs.frame) self.$refs.frame.contentWindow.postMessage({ jsonrpc: '2.0', id: msg.id, result: result }, '*');
                                                                    })
                                                                    .catch(function(err) {
                                                                        if (self.$refs.frame) self.$refs.frame.contentWindow.postMessage({ jsonrpc: '2.0', id: msg.id, error: { code: -32000, message: err.message || 'Error' } }, '*');
                                                                    });
                                                            }
                                                        };
                                                        window.addEventListener('message', self._msgHandler);
                                                        this.$cleanup(function() { window.removeEventListener('message', self._msgHandler); });
                                                    }
                                                 }"
                                                 data-apphtml="{{ base64_encode($mcpHtml) }}">
                                                <iframe x-ref="frame"
                                                    sandbox="allow-scripts"
                                                    style="width:100%;height:420px;border:0;border-radius:8px;background:#0f0f0f;display:block"
                                                    title="{{ $mcpToolName }} app"></iframe>
                                            </div>
                                        @endif
                                    @endforeach
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
                        <i class="fa-solid fa-microphone text-lg"></i>
                    </button>
                    <div class="relative flex-shrink-0">
                        <button
                            type="button"
                            x-on:click="send()"
                            :disabled="!inputText.trim()"
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{-- Spinner when job is active --}}
                            <i x-show="sending || $wire.pendingMessageId !== ''" class="fa-solid fa-spinner fa-spin text-lg"></i>
                            {{-- Send arrow when idle --}}
                            <i x-show="!sending && $wire.pendingMessageId === ''" class="fa-solid fa-paper-plane text-lg"></i>
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

    {{-- Gap 2 hardening: pop-out modal for wide artifacts (data_table > 10 rows). --}}
    <x-assistant.artifacts.popout-modal />

    {{-- Gap 2 hardening: mobile force-collapse below 360px panel width. --}}
    <script>
        (function () {
            function collapseMobileArtifacts() {
                if (window.innerWidth >= 360) {
                    return;
                }
                document.querySelectorAll('.assistant-response + div details[open]').forEach(function (el) {
                    el.removeAttribute('open');
                });
            }
            document.addEventListener('DOMContentLoaded', collapseMobileArtifacts);
            document.addEventListener('livewire:navigated', collapseMobileArtifacts);
            document.addEventListener('livewire:update', collapseMobileArtifacts);
        })();
    </script>
</div>