<x-layouts.docs
    title="Chatbots & Telegram"
    description="FleetQ lets you deploy AI-powered chatbots via web widgets or Telegram. Each chatbot is backed by a FleetQ agent and can learn from conversations."
    page="chatbots"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Chatbots & Telegram</h1>
    <p class="mt-4 text-gray-600">
        FleetQ lets you deploy AI-powered chatbots that interact with users via embeddable web widgets or
        Telegram. Every chatbot is backed by a FleetQ <a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agent</a>,
        inheriting its model, skills, tools, and prompt configuration. Conversations are stored, analysed,
        and can feed back into the agent via learning entries.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A SaaS company deploys a support chatbot on their docs site and a
        Telegram bot for their community. Both are powered by the same "Support Agent" in FleetQ.
        Corrections made via learning entries automatically improve future responses.</em>
    </p>

    {{-- Overview --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Overview</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Web Widget</p>
            <p class="mt-1 text-sm text-gray-600">
                Embed a chat widget on any website with a one-line JavaScript snippet.
                Each visitor gets an isolated session.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Telegram Bot</p>
            <p class="mt-1 text-sm text-gray-600">
                Connect a Telegram bot to route messages to the FleetQ assistant,
                a specific agent, or a workflow.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Learning</p>
            <p class="mt-1 text-sm text-gray-600">
                Corrections and feedback are stored as learning entries and used
                to improve future agent responses.
            </p>
        </div>
    </div>

    {{-- Creating a Chatbot --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Creating a chatbot</h2>
    <p class="mt-2 text-sm text-gray-600">
        Navigate to <strong>Chatbots</strong> in the sidebar and click <strong>New Chatbot</strong>,
        or use the API. The following fields are available:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Field</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">name</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Display name for the chatbot (shown in the widget header).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">description</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Internal description for team reference.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_id</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">The FleetQ agent that powers this chatbot. The agent's model, tools, and skills are all applied.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">greeting_message</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Opening message shown to users when they start a conversation.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">appearance</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">JSONB object controlling widget colours, position, and avatar.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash">
curl -X POST /api/v1/chatbots \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Support Bot",
    "description": "Customer-facing support chatbot",
    "agent_id": "AGENT_ID",
    "greeting_message": "Hi! How can I help you today?",
    "appearance": {
      "primary_color": "#6366f1",
      "position": "bottom-right"
    }
  }'</x-docs.code>

    {{-- Chatbot Features --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Chatbot features</h2>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Web widget embed</h3>
    <p class="mt-2 text-sm text-gray-600">
        Once a chatbot is created, copy the JavaScript snippet from its detail page and paste it before
        the closing <code class="rounded bg-gray-100 px-1 text-xs">&lt;/body&gt;</code> tag on any page.
    </p>
    <x-docs.code lang="html">
&lt;script
  src="https://your-fleetq-domain.com/widget.js"
  data-chatbot-id="CHATBOT_ID"
  data-token="CHATBOT_API_TOKEN"
  async
&gt;&lt;/script&gt;</x-docs.code>

    <x-docs.callout type="tip">
        The widget script is loaded asynchronously and does not block page rendering.
        Each chatbot has its own API token separate from your personal Sanctum token — see
        <strong>Chatbot API Tokens</strong> below.
    </x-docs.callout>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Session management</h3>
    <p class="mt-2 text-sm text-gray-600">
        Every browser visitor receives a separate, isolated conversation. Sessions are identified by a
        unique session token stored in <code class="rounded bg-gray-100 px-1 text-xs">localStorage</code>.
        Conversations persist across page reloads for the same visitor. Anonymous visitors are tracked
        by session; authenticated visitors can be identified by passing a <code class="rounded bg-gray-100 px-1 text-xs">user_id</code>
        attribute to the widget script.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Learning entries</h3>
    <p class="mt-2 text-sm text-gray-600">
        Operators can mark a chatbot response as incorrect and provide the expected answer. This creates
        a <strong>learning entry</strong> associated with the chatbot. The backing agent incorporates
        learning entries into its context on subsequent requests, improving accuracy over time without
        requiring a full fine-tune.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Analytics</h3>
    <p class="mt-2 text-sm text-gray-600">
        The chatbot analytics summary tracks:
    </p>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li>Total conversations and messages</li>
        <li>Average response quality score</li>
        <li>User satisfaction ratings (thumbs up / thumbs down)</li>
        <li>Most common topics and unanswered questions</li>
    </ul>

    {{-- API Tokens --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Chatbot API tokens</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each chatbot uses a dedicated API token scoped to that chatbot only. This token is embedded in
        the widget snippet and authenticates widget requests. Generate a token via the UI or the API:
    </p>
    <x-docs.code lang="bash">
POST /api/v1/chatbots/{id}/tokens</x-docs.code>

    <x-docs.callout type="info">
        Chatbot tokens grant access only to the widget conversation endpoints — they cannot access other
        FleetQ resources. Rotate them independently of your personal API tokens.
    </x-docs.callout>

    {{-- Telegram --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Telegram bots</h2>
    <p class="mt-2 text-sm text-gray-600">
        Connect FleetQ to Telegram by registering a Telegram bot. Incoming Telegram messages are
        routed through FleetQ and processed by a configurable routing mode.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Routing modes</h3>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Mode</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">assistant</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Messages are routed to the FleetQ AI assistant, which has access to all platform tools and conversations.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Messages are sent directly to a specific FleetQ agent for execution. Best for task-focused bots.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">workflow</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Incoming messages trigger a FleetQ workflow. The message text is passed as the workflow input.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Chat bindings</h3>
    <p class="mt-2 text-sm text-gray-600">
        A <strong>chat binding</strong> maps a specific Telegram chat (group, channel, or DM) to a
        FleetQ conversation context. Bindings allow you to route messages from different Telegram chats
        to different agents or workflows, keeping conversations isolated.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Webhook-based delivery</h3>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ receives Telegram updates via the Telegram Bot API webhook at
        <code class="rounded bg-gray-100 px-1 text-xs">/api/telegram/webhook/{teamId}</code>.
        The webhook is authenticated with a secret token set during bot registration.
        Incoming updates are queued and processed asynchronously via
        <code class="rounded bg-gray-100 px-1 text-xs">ProcessTelegramMessageJob</code>.
    </p>

    {{-- Telegram Setup --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Telegram setup</h2>
    <div class="mt-4 space-y-2">
        <x-docs.step number="1" title="Create a bot via @BotFather">
            Open Telegram and message <code class="rounded bg-gray-100 px-1 text-xs">@BotFather</code>.
            Send <code class="rounded bg-gray-100 px-1 text-xs">/newbot</code>, choose a name and username,
            then copy the bot token provided.
        </x-docs.step>
        <x-docs.step number="2" title="Register the bot in FleetQ">
            Go to <strong>Team Settings → Integrations → Telegram</strong> and click <strong>Add Bot</strong>,
            or use the MCP tool:
            <x-docs.code lang="bash" class="mt-2">
# Via MCP tool
telegram_bot_manage(action="register", bot_token="YOUR_BOT_TOKEN", routing_mode="assistant")</x-docs.code>
        </x-docs.step>
        <x-docs.step number="3" title="Webhook auto-registration">
            FleetQ automatically calls the Telegram Bot API to register its webhook URL.
            No manual <code class="rounded bg-gray-100 px-1 text-xs">setWebhook</code> call is needed.
            The secret token used to authenticate incoming requests is generated and stored automatically.
        </x-docs.step>
        <x-docs.step number="4" title="Configure routing">
            Update the routing mode or bind specific chats to agents or workflows as needed.
            Changes take effect immediately — no restart required.
        </x-docs.step>
    </div>

    <x-docs.callout type="tip">
        FleetQ must be accessible over HTTPS for Telegram webhooks to work. Telegram requires a valid
        TLS certificate on port 443. In local development, use a tunnel such as
        <code class="rounded bg-gray-100 px-1 text-xs">ngrok</code> or <code class="rounded bg-gray-100 px-1 text-xs">expose</code>.
    </x-docs.callout>

    {{-- MCP Tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        All chatbot and Telegram operations are available as MCP tools for LLM agents and
        <a href="{{ route('docs.show', 'assistant') }}" class="text-primary-600 hover:underline">the FleetQ assistant</a>.
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all chatbots with status and agent assignment.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve full details of a single chatbot including appearance settings.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new chatbot backed by a specific agent.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update chatbot name, description, greeting, appearance, or backing agent.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_toggle_status</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Enable or disable a chatbot. Disabled chatbots stop accepting new conversations.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_session_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List active and historical sessions for a chatbot.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_analytics_summary</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Return conversation counts, response quality scores, and satisfaction ratings.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">chatbot_learning_entries</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List or add learning entries (corrections and preferred responses) for a chatbot.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">telegram_bot_manage</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Register, update, or remove a Telegram bot. Supports actions: <code class="rounded bg-gray-100 px-1">register</code>, <code class="rounded bg-gray-100 px-1">update</code>, <code class="rounded bg-gray-100 px-1">delete</code>.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API Endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        Full OpenAPI 3.1 documentation is available at
        <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a>.
        All endpoints require a Sanctum bearer token.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Endpoint</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-blue-50 px-1.5 py-0.5 font-mono text-xs font-medium text-blue-700">GET</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all chatbots (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-blue-50 px-1.5 py-0.5 font-mono text-xs font-medium text-blue-700">GET</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single chatbot.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-green-50 px-1.5 py-0.5 font-mono text-xs font-medium text-green-700">POST</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a chatbot.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-yellow-50 px-1.5 py-0.5 font-mono text-xs font-medium text-yellow-700">PUT</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update a chatbot.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-red-50 px-1.5 py-0.5 font-mono text-xs font-medium text-red-700">DELETE</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a chatbot and all associated sessions.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-green-50 px-1.5 py-0.5 font-mono text-xs font-medium text-green-700">POST</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots/{id}/tokens</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Generate a new API token for the widget embed.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6">
                        <span class="rounded bg-blue-50 px-1.5 py-0.5 font-mono text-xs font-medium text-blue-700">GET</span>
                    </td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/chatbots/{id}/conversations</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all conversations for a chatbot (cursor-paginated).</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.docs>
