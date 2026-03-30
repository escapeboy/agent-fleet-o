<div
    x-data="voiceSession(@entangle('session').live)"
    x-init="init()"
    class="min-h-screen bg-gray-50 p-6"
>
    {{-- Page header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Voice Session</h1>
            <p class="mt-1 text-sm text-gray-500">
                Agent: <span class="font-medium text-gray-700">{{ $agent->name }}</span>
            </p>
        </div>
        <a href="{{ route('agents.show', $agent) }}" class="text-sm text-gray-500 hover:text-gray-700">
            &larr; Back to agent
        </a>
    </div>

    {{-- No LiveKit integration configured --}}
    @if ($needsIntegration)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-amber-800">LiveKit not configured</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        Voice sessions require a LiveKit account. Connect your LiveKit credentials in the Integrations page to enable real-time voice conversations with your agents.
                    </p>
                    <div class="mt-4 flex items-center gap-3">
                        <a
                            href="{{ route('integrations.index') }}"
                            class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                        >
                            Connect LiveKit
                        </a>
                        <a
                            href="https://livekit.io"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm text-amber-700 hover:text-amber-900 underline"
                        >
                            Get a free LiveKit account &rarr;
                        </a>
                    </div>
                    <p class="mt-3 text-xs text-amber-600">
                        Self-hosted deployments can also set <code class="font-mono">LIVEKIT_API_KEY</code> and <code class="font-mono">LIVEKIT_API_SECRET</code> in <code class="font-mono">.env</code>.
                    </p>
                </div>
            </div>
        </div>

    @else

        {{-- Error notice --}}
        @if ($error)
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                {{ $error }}
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            {{-- Session controls --}}
            <div class="lg:col-span-1">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 text-base font-semibold text-gray-800">Session Controls</h2>

                    {{-- Status indicator --}}
                    <div class="mb-4 flex items-center gap-2">
                        <span
                            class="inline-block h-2.5 w-2.5 rounded-full"
                            :class="{
                                'bg-gray-300': connectionState === 'disconnected',
                                'bg-yellow-400 animate-pulse': connectionState === 'connecting',
                                'bg-green-500': connectionState === 'connected',
                                'bg-red-400': connectionState === 'error',
                            }"
                        ></span>
                        <span class="text-sm text-gray-600" x-text="statusLabel"></span>
                    </div>

                    {{-- Start button --}}
                    <template x-if="connectionState === 'disconnected'">
                        <button
                            wire:click="startSession"
                            x-on:click="onStartClicked"
                            class="w-full rounded-md bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50"
                            :disabled="connectionState !== 'disconnected'"
                        >
                            Start Voice Session
                        </button>
                    </template>

                    {{-- End button --}}
                    <template x-if="connectionState !== 'disconnected'">
                        <button
                            x-on:click="onEndClicked"
                            class="w-full rounded-md bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                        >
                            End Session
                        </button>
                    </template>

                    {{-- Mic indicator --}}
                    <div class="mt-4 flex items-center gap-2 text-sm text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
                        </svg>
                        <span x-text="micLabel">Microphone inactive</span>
                    </div>

                    {{-- Room info (for debugging) --}}
                    @if ($session)
                        <div class="mt-4 rounded-md bg-gray-50 p-3 text-xs text-gray-500">
                            <div class="font-mono break-all">Room: {{ $session->room_name }}</div>
                            <div class="mt-1">Status: {{ $session->status->value }}</div>
                        </div>
                    @endif
                </div>

                {{-- Voice pipeline info --}}
                <div class="mt-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-2 text-sm font-semibold text-gray-700">Voice Pipeline</h3>
                    <p class="text-xs text-gray-500 leading-relaxed">
                        Connected to LiveKit at
                        <code class="font-mono break-all text-xs text-gray-600">{{ $livekitUrl }}</code>.
                    </p>
                    <p class="mt-2 text-xs text-gray-500 leading-relaxed">
                        The voice worker handles STT → LLM → TTS. If no response is heard,
                        ensure the voice worker is running and connected to this LiveKit server.
                    </p>
                    <a href="{{ route('integrations.index') }}" class="mt-2 inline-block text-xs text-primary-600 hover:text-primary-700">
                        Manage LiveKit integration &rarr;
                    </a>
                </div>
            </div>

            {{-- Live transcript --}}
            <div class="lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm" style="height: 600px; display: flex; flex-direction: column;">
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h2 class="text-base font-semibold text-gray-800">Live Transcript</h2>
                    </div>

                    <div
                        class="flex-1 overflow-y-auto p-5 space-y-3"
                        id="transcript-container"
                        wire:poll.3s="refreshTranscript"
                    >
                        @forelse ($transcript as $turn)
                            <div class="flex gap-3 {{ $turn['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                                <div class="flex-shrink-0">
                                    @if ($turn['role'] === 'user')
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">You</span>
                                    @elseif ($turn['role'] === 'agent')
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-violet-100 text-xs font-semibold text-violet-700">AI</span>
                                    @else
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-500">Sys</span>
                                    @endif
                                </div>
                                <div class="max-w-prose rounded-lg px-3.5 py-2.5 text-sm {{ $turn['role'] === 'user' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-800' }}">
                                    {{ $turn['content'] }}
                                    <div class="mt-1 text-xs opacity-60">{{ $turn['timestamp'] ?? '' }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="flex h-full items-center justify-center text-sm text-gray-400">
                                No transcript yet. Start a session and speak to see messages appear here.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>

    @endif

</div>

{{--
    Alpine.js voice session component.

    This stub wires Livewire actions to the browser state machine.
    Full WebRTC implementation requires @livekit/client npm package
    (npm install @livekit/client) and Vite integration.

    Once @livekit/client is installed, replace the TODO comments below
    with the actual LiveKit room connection code. See:
    https://docs.livekit.io/client-sdk-js/
--}}
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('voiceSession', (initialSession) => ({
            session: initialSession,
            connectionState: 'disconnected', // disconnected | connecting | connected | error
            micActive: false,

            // LiveKit room instance (requires @livekit/client)
            // room: null,

            init() {
                if (this.session) {
                    this.connectionState = 'connecting';
                }
                // Auto-scroll transcript to bottom on updates
                this.$watch('$wire.transcript', () => this.scrollToBottom());
            },

            get statusLabel() {
                const labels = {
                    disconnected: 'Not connected',
                    connecting:   'Connecting...',
                    connected:    'Connected — listening',
                    error:        'Connection error',
                };
                return labels[this.connectionState] ?? this.connectionState;
            },

            get micLabel() {
                return this.micActive ? 'Microphone active' : 'Microphone inactive';
            },

            async onStartClicked() {
                this.connectionState = 'connecting';

                // Call Livewire action which persists the session and returns connection info
                const info = await this.$wire.startSession();

                if (! info || ! info.token) {
                    this.connectionState = 'error';
                    return;
                }

                // TODO: Connect to LiveKit room using @livekit/client
                // import { Room, RoomEvent } from 'livekit-client';
                // this.room = new Room();
                // await this.room.connect(info.livekit_url, info.token);
                // await this.room.localParticipant.setMicrophoneEnabled(true);
                // this.micActive = true;
                // this.connectionState = 'connected';
                //
                // this.room.on(RoomEvent.Disconnected, () => {
                //     this.connectionState = 'disconnected';
                //     this.micActive = false;
                // });

                // Stub: mark connected for UI demonstration
                console.log('LiveKit room info:', info);
                this.connectionState = 'connected';
                this.micActive = true;
            },

            async onEndClicked() {
                // TODO: Disconnect from LiveKit room
                // if (this.room) {
                //     await this.room.disconnect();
                //     this.room = null;
                // }

                this.micActive = false;
                this.connectionState = 'disconnected';

                await this.$wire.endSession();
            },

            scrollToBottom() {
                this.$nextTick(() => {
                    const el = document.getElementById('transcript-container');
                    if (el) { el.scrollTop = el.scrollHeight; }
                });
            },
        }));
    });
</script>
