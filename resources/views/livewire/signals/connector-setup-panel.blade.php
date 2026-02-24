<div>
    @if($open)
        {{-- Backdrop --}}
        <div
            x-data
            x-show="true"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-black/40"
            wire:click="close"
        ></div>

        {{-- Slide-over panel --}}
        <div
            x-data
            x-show="true"
            x-transition:enter="transition ease-out duration-250"
            x-transition:enter-start="translate-x-full opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0 opacity-100"
            x-transition:leave-end="translate-x-full opacity-0"
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col overflow-hidden border-l border-(--color-theme-border) bg-(--color-surface-raised) shadow-2xl"
        >
            {{-- Header --}}
            <div class="flex shrink-0 items-center justify-between border-b border-(--color-theme-border) px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-700">
                        <span class="text-sm font-bold">{{ strtoupper(substr($connectorLabel, 0, 2)) }}</span>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-(--color-on-surface)">{{ $connectorLabel }}</h2>
                        <p class="text-xs text-(--color-on-surface-muted)">{{ $connectorCategory }}</p>
                    </div>
                </div>
                <button wire:click="close"
                    class="rounded-lg p-1.5 text-(--color-on-surface-muted) hover:bg-(--color-surface-alt)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">

                {{-- Webhook URL --}}
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-(--color-on-surface)">Webhook URL</h3>
                    <div
                        x-data="{
                            copied: false,
                            url: '{{ $webhookUrl }}',
                            copy() {
                                navigator.clipboard.writeText(this.url).then(() => {
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 2000);
                                });
                            }
                        }"
                        class="flex items-center gap-2"
                    >
                        <input
                            type="text"
                            :value="url"
                            readonly
                            class="w-full cursor-pointer rounded-lg border border-(--color-theme-border) bg-(--color-surface-alt) py-2 pl-3 pr-3 font-mono text-xs text-(--color-on-surface) focus:border-primary-400 focus:outline-none focus:ring-1 focus:ring-primary-400"
                            @click="$el.select()"
                        />
                        <button
                            @click="copy()"
                            class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition"
                            :class="copied
                                ? 'border-green-300 bg-green-50 text-green-700'
                                : 'border-(--color-theme-border-strong) text-(--color-on-surface) hover:bg-(--color-surface-alt)'"
                        >
                            <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5A3.375 3.375 0 006.375 7.5H5.25m11.9 3.75H18" />
                            </svg>
                            <svg x-show="copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <span x-text="copied ? 'Copied!' : 'Copy'" aria-live="polite"></span>
                        </button>
                    </div>
                </div>

                {{-- Signing Secret Status --}}
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-(--color-on-surface)">Signing Secret</h3>
                    @if($secretConfigured)
                        <div class="flex items-center gap-2 text-green-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-sm font-medium">Configured</span>
                        </div>
                        <p class="text-xs text-(--color-on-surface-muted)">Webhook payloads are verified using HMAC-SHA256.</p>
                    @else
                        <div class="flex items-center gap-2 text-amber-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <span class="text-sm font-medium">Not configured</span>
                        </div>
                        @if($envVar)
                            <p class="text-xs text-(--color-on-surface-muted)">
                                Add <code class="rounded bg-(--color-surface-alt) px-1 font-mono">{{ $envVar }}</code> to your <code class="rounded bg-(--color-surface-alt) px-1 font-mono">.env</code> file to enable signature verification.
                            </p>
                        @endif
                    @endif
                </div>

                {{-- Setup Guide (collapsible) --}}
                <div
                    x-data="{ open: {{ $secretConfigured ? 'false' : 'true' }} }"
                    class="space-y-3"
                >
                    <button
                        @click="open = !open"
                        class="flex w-full items-center justify-between text-sm font-semibold text-(--color-on-surface)"
                    >
                        <span>Setup Guide</span>
                        <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div x-show="open" x-transition class="space-y-4">
                        @php
                            $steps = match($driver) {
                                'github' => [
                                    'Go to your GitHub repository → <strong>Settings → Webhooks → Add webhook</strong>.',
                                    'Set <strong>Payload URL</strong> to the URL above.',
                                    'Set <strong>Content type</strong> to <code class="rounded bg-(--color-surface-alt) px-1">application/json</code>.',
                                    'Set <strong>Secret</strong> to the value of <code class="rounded bg-(--color-surface-alt) px-1">GITHUB_WEBHOOK_SECRET</code> in your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Under <strong>Which events?</strong>, select individual events: <em>Issues, Pull requests, Pushes, Workflow runs, Releases</em>.',
                                    'Click <strong>Add webhook</strong>. GitHub sends a ping event immediately — you should see your first signal.',
                                ],
                                'slack' => [
                                    'Go to <strong>api.slack.com/apps</strong> → Create or select your app.',
                                    'Under <strong>Event Subscriptions</strong>, enable Events and set Request URL to the URL above.',
                                    'Under <strong>Subscribe to bot events</strong>, add: <code class="rounded bg-(--color-surface-alt) px-1">message.channels</code>, <code class="rounded bg-(--color-surface-alt) px-1">app_mention</code>, <code class="rounded bg-(--color-surface-alt) px-1">reaction_added</code>.',
                                    'Copy the <strong>Signing Secret</strong> from Basic Information and add <code class="rounded bg-(--color-surface-alt) px-1">SLACK_SIGNING_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Under <strong>OAuth &amp; Permissions</strong>, add scopes: <code class="rounded bg-(--color-surface-alt) px-1">channels:history</code>, <code class="rounded bg-(--color-surface-alt) px-1">channels:read</code>.',
                                    'Install the app to your workspace and invite the bot to channels you want to monitor.',
                                ],
                                'jira' => [
                                    'Go to <strong>Jira Admin → System → WebHooks → Create a WebHook</strong>.',
                                    'Set URL to the URL above.',
                                    'Under <strong>Events</strong>, enable: Issue created, Issue updated.',
                                    'Optionally set a secret and add <code class="rounded bg-(--color-surface-alt) px-1">JIRA_WEBHOOK_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Click <strong>Create</strong>.',
                                ],
                                'linear' => [
                                    'Go to <strong>Linear Settings → API → Webhooks → New webhook</strong>.',
                                    'Set URL to the URL above.',
                                    'Set a signing secret and add <code class="rounded bg-(--color-surface-alt) px-1">LINEAR_WEBHOOK_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Enable resource types: <strong>Issue</strong>.',
                                    'Click <strong>Create webhook</strong>.',
                                ],
                                'discord' => [
                                    'Go to your Discord server settings → <strong>Integrations → Webhooks → New Webhook</strong>.',
                                    'Set the Webhook URL field to the URL above.',
                                    'Optionally add <code class="rounded bg-(--color-surface-alt) px-1">DISCORD_WEBHOOK_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Click <strong>Save</strong>.',
                                ],
                                'sentry' => [
                                    'Go to <strong>Sentry Organization Settings → Developer Settings → Internal Integrations</strong>.',
                                    'Create a new integration and add the URL above as a webhook endpoint.',
                                    'Add <code class="rounded bg-(--color-surface-alt) px-1">SENTRY_WEBHOOK_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Enable events: <strong>issue, error</strong>.',
                                ],
                                'pagerduty' => [
                                    'Go to <strong>PagerDuty → Integrations → Generic Webhooks (V3)</strong>.',
                                    'Add the URL above as a new webhook subscription.',
                                    'Add <code class="rounded bg-(--color-surface-alt) px-1">PAGERDUTY_AUTH_TOKEN</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Select event types: <strong>incident.triggered, incident.resolved</strong>.',
                                ],
                                'datadog' => [
                                    'The URL above contains your unique secret — copy it exactly as shown.',
                                    'Go to <strong>Datadog → Integrations → Webhooks → New Webhook</strong>.',
                                    'Paste the full URL (including the secret segment at the end) into the URL field.',
                                    'Select the events to forward: <em>monitor alert, monitor recovery</em>.',
                                    'Click <strong>Save</strong>. Use the "Manage" button to rotate the URL secret if compromised.',
                                ],
                                'whatsapp' => [
                                    'Go to <strong>Meta for Developers → Your App → WhatsApp → Configuration</strong>.',
                                    'Set <strong>Callback URL</strong> to the URL above.',
                                    'Set a <strong>Verify Token</strong> and add <code class="rounded bg-(--color-surface-alt) px-1">WHATSAPP_VERIFY_TOKEN</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code>.',
                                    'Add <code class="rounded bg-(--color-surface-alt) px-1">WHATSAPP_APP_SECRET</code> to your <code class="rounded bg-(--color-surface-alt) px-1">.env</code> for payload verification.',
                                    'Under <strong>Webhook Fields</strong>, subscribe to: <strong>messages</strong>.',
                                ],
                                default => [],
                            };
                        @endphp

                        @foreach($steps as $i => $step)
                            <div class="flex gap-3">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">
                                    {{ $i + 1 }}
                                </div>
                                <p class="flex-1 text-sm text-(--color-on-surface)">{!! $step !!}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Verify Connection --}}
                <div class="space-y-2 rounded-lg border border-(--color-theme-border) bg-(--color-surface-alt) p-4">
                    <h3 class="text-sm font-semibold text-(--color-on-surface)">Verify Connection</h3>
                    <p class="text-xs text-(--color-on-surface-muted)">Check if any signals from this connector arrived in the last hour.</p>
                    <div class="flex items-center gap-3">
                        <button wire:click="checkRecentEvents"
                            wire:loading.attr="disabled"
                            wire:target="checkRecentEvents"
                            class="rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) hover:bg-(--color-surface-raised) disabled:opacity-50">
                            <span wire:loading.remove wire:target="checkRecentEvents">Check Recent Events</span>
                            <span wire:loading wire:target="checkRecentEvents">Checking...</span>
                        </button>
                        @if($checked)
                            <span class="text-sm {{ $recentSignalCount > 0 ? 'text-green-600 font-medium' : 'text-(--color-on-surface-muted)' }}">
                                {{ $recentSignalCount > 0 ? '&#10003; ' . $recentSignalCount . ' ' . Str::plural('signal', $recentSignalCount) . ' in the last hour' : 'No signals in the last hour' }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex shrink-0 items-center justify-end border-t border-(--color-theme-border) px-6 py-4">
                <button wire:click="close"
                    class="rounded-lg border border-(--color-theme-border-strong) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                    Close
                </button>
            </div>
        </div>
    @endif
</div>
