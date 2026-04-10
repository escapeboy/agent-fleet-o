<x-layouts.docs
    title="Voice Agents"
    description="Run real-time voice conversations with FleetQ agents over LiveKit — WebRTC client, STT→LLM→TTS pipeline, transcripts, and MCP control."
    page="voice-agents"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Voice Agents — Real-Time Voice Conversations</h1>
    <p class="mt-4 text-gray-600">
        Voice Agents let users talk to any FleetQ <a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agent</a>
        in real time over WebRTC. A browser client captures audio, a server-side worker streams it through
        speech-to-text, dispatches the transcript to the agent's LLM, speaks the response via text-to-speech,
        and sends the audio back — all with full-duplex streaming and sub-second latency.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A customer calls a FleetQ-powered support line from the web. The
        "Tier-1 Support" agent answers, looks up the caller's account in a connected CRM via MCP, walks them
        through a password reset, and logs a transcript for QA review — all without human involvement.</em>
    </p>

    <x-docs.callout type="info">
        <strong>Enterprise-only feature.</strong> Voice sessions require the <code class="font-mono text-xs">voice_agent</code>
        feature flag on your plan, the <code class="font-mono text-xs">voice-worker</code> Docker service,
        and a LiveKit Cloud project. Get in touch with support to enable it on your team.
    </x-docs.callout>

    {{-- Architecture --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Architecture</h2>
    <p class="mt-2 text-sm text-gray-600">
        Voice sessions combine four moving parts:
    </p>
    <div class="mt-4 space-y-3">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">1. Browser client</p>
            <p class="mt-1 text-sm text-gray-600">
                Uses <code class="font-mono text-xs">@livekit/client</code> to open a WebRTC connection to LiveKit Cloud,
                publish the user's microphone track, and play the agent's audio track back. The client runs
                entirely in the page — no additional SDK needed.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">2. LiveKit room</p>
            <p class="mt-1 text-sm text-gray-600">
                A short-lived room is created per session. FleetQ issues scoped JWTs to both the user and the
                voice worker. Rooms auto-destruct when the session ends or times out.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">3. Voice worker (Python)</p>
            <p class="mt-1 text-sm text-gray-600">
                A Docker service (profile: <code class="font-mono text-xs">voice</code>) runs a Python
                LiveKit Agents process. It connects to the room, pipes audio through
                <strong>Deepgram/Whisper STT</strong>, sends the transcript to a FleetQ agent via the internal
                LLM gateway, and returns the response via <strong>ElevenLabs/OpenAI TTS</strong>.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">4. Redis dispatch</p>
            <p class="mt-1 text-sm text-gray-600">
                FleetQ publishes a job to Redis when a session is requested; the voice worker subscribes and
                picks it up. This keeps the Laravel request/response cycle decoupled from the long-lived audio session.
            </p>
        </div>
    </div>

    {{-- Session lifecycle --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Session lifecycle</h2>
    <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600">
        <li>Client calls <code class="font-mono text-xs">voice_session_create</code> with an <code class="font-mono text-xs">agent_id</code>.</li>
        <li>FleetQ creates a LiveKit room, issues JWTs, dispatches a job to the voice worker, and returns the connection details to the browser.</li>
        <li>Browser joins the room. The voice worker joins in parallel and connects STT, TTS, and the agent LLM.</li>
        <li>Real-time audio flows in both directions. Each user turn is transcribed, fed to the agent, and the response spoken back.</li>
        <li>Session ends automatically (user disconnect, idle timeout) or explicitly via <code class="font-mono text-xs">voice_session_end</code>.</li>
        <li>The worker writes a full transcript to the <code class="font-mono text-xs">VoiceSession</code> record, retrievable via <code class="font-mono text-xs">voice_session_get_transcript</code>.</li>
    </ol>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        Voice session lifecycle is fully controllable via the MCP server. The <code class="font-mono text-xs">VoiceSession</code>
        tool group is registered in <code class="font-mono text-xs">AgentFleetServer</code> when the
        <code class="font-mono text-xs">voice_agent</code> feature flag is active for the calling team.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">voice_session_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Start a voice session with a specified agent. Returns LiveKit connection details and a session UUID.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">voice_session_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List voice sessions for the team (active + historical).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">voice_session_end</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">End an active voice session cleanly (the worker flushes the transcript on exit).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">voice_session_get_transcript</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve the full transcript of a completed session, including per-turn timings.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Configuration --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Configuration</h2>
    <p class="mt-2 text-sm text-gray-600">
        Enabling voice requires the following:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Requirement</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Feature flag</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600"><code class="rounded bg-gray-100 px-1">voice_agent</code> must be enabled on the team's plan (Enterprise).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Docker service</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Start the stack with profile <code class="rounded bg-gray-100 px-1">voice</code>: <code class="rounded bg-gray-100 px-1">docker compose --profile voice up -d voice-worker</code></td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">LiveKit project</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Provide <code class="rounded bg-gray-100 px-1">LIVEKIT_URL</code>, <code class="rounded bg-gray-100 px-1">LIVEKIT_API_KEY</code>, and <code class="rounded bg-gray-100 px-1">LIVEKIT_API_SECRET</code> in <code class="rounded bg-gray-100 px-1">.env</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">STT / TTS credentials</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Deepgram or OpenAI Whisper for STT; ElevenLabs or OpenAI TTS for speech synthesis. Store as team-scoped credentials.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Agent</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Any FleetQ agent can back a voice session — the usual role/goal/backstory, tools, and skills apply.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Because a voice session is backed by a regular FleetQ agent, everything else the agent can do — calling
        MCP tools, reading memory, consulting the knowledge graph, checking budget — Just Works mid-conversation.
    </x-docs.callout>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agents</a> — the underlying worker for every voice session.</li>
        <li><a href="{{ route('docs.show', 'chatbots') }}" class="text-primary-600 hover:underline">Chatbots &amp; Telegram</a> — text-based equivalents of voice sessions.</li>
        <li><a href="{{ route('docs.show', 'tools') }}" class="text-primary-600 hover:underline">Tools</a> — agents called from voice can use any attached tool, including MCP servers and bash.</li>
    </ul>
</x-layouts.docs>
