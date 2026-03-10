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
                    <p class="text-xs text-(--color-on-surface-muted)">This URL is unique to your team. Configure it as the webhook endpoint in {{ $connectorLabel }}.</p>
                </div>

                {{-- Signing Secret --}}
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-(--color-on-surface)">{{ $secretLabel }}</h3>
                        @if($secretMode === 'generated' && $secretHint && !$showSecret)
                            @if(!$confirmingRotate)
                                <button wire:click="confirmRotate"
                                    class="text-xs text-(--color-on-surface-muted) hover:text-red-600 transition">
                                    Regenerate
                                </button>
                            @endif
                        @endif
                    </div>

                    @if($showSecret && $rawSecret)
                        {{-- One-time reveal: show full secret after generation or rotation --}}
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3">
                            <div class="flex items-start gap-2">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                                <p class="text-xs font-medium text-amber-800">Copy this secret now — it won't be shown again.</p>
                            </div>
                            <div
                                x-data="{
                                    copied: false,
                                    secret: '{{ $rawSecret }}',
                                    copy() {
                                        navigator.clipboard.writeText(this.secret).then(() => {
                                            this.copied = true;
                                            setTimeout(() => this.copied = false, 2000);
                                        });
                                    }
                                }"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="text"
                                    :value="secret"
                                    readonly
                                    class="w-full cursor-pointer rounded-lg border border-amber-300 bg-white py-2 pl-3 pr-3 font-mono text-xs text-gray-900 focus:outline-none"
                                    @click="$el.select()"
                                />
                                <button
                                    @click="copy()"
                                    class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition"
                                    :class="copied
                                        ? 'border-green-300 bg-green-50 text-green-700'
                                        : 'border-amber-300 bg-amber-100 text-amber-800 hover:bg-amber-200'"
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
                            <p class="text-xs text-amber-700">{!! $secretHintText !!}</p>
                            <button wire:click="dismissSecret" class="text-xs text-amber-700 underline hover:text-amber-900">
                                I've copied it — dismiss
                            </button>
                        </div>

                    @elseif($secretMode === 'generated')
                        {{-- Masked secret — only hint shown after initial setup --}}
                        @if($secretHint)
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2 text-green-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="text-sm font-medium">Configured</span>
                                </div>
                                <code class="rounded bg-(--color-surface-alt) px-2 py-0.5 font-mono text-xs text-(--color-on-surface-muted)">{{ $secretHint }}</code>
                            </div>
                            <p class="text-xs text-(--color-on-surface-muted)">{!! $secretHintText !!}</p>
                        @else
                            <div class="flex items-center gap-2 text-amber-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                                <span class="text-sm font-medium">Not yet generated</span>
                            </div>
                        @endif

                        {{-- Rotation confirmation modal --}}
                        @if($confirmingRotate)
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 space-y-3">
                                <p class="text-sm font-medium text-red-800">Regenerate signing secret?</p>
                                <p class="text-xs text-red-700">
                                    Your current secret will remain valid for <strong>1 hour</strong> while you update the webhook configuration in {{ $connectorLabel }}.
                                    After that, only the new secret will be accepted.
                                </p>
                                <div class="flex gap-2">
                                    <button wire:click="rotateSecret"
                                        wire:loading.attr="disabled"
                                        wire:target="rotateSecret"
                                        class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="rotateSecret">Yes, regenerate</span>
                                        <span wire:loading wire:target="rotateSecret">Regenerating...</span>
                                    </button>
                                    <button wire:click="cancelRotate" class="rounded-lg border border-(--color-theme-border) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif

                    @elseif($secretMode === 'paste')
                        {{-- Paste-mode: user enters the service-provided signing key --}}
                        @if($secretHint)
                            <div class="flex items-center gap-2 text-green-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="text-sm font-medium">Configured</span>
                                <code class="rounded bg-(--color-surface-alt) px-2 py-0.5 font-mono text-xs text-(--color-on-surface-muted)">{{ $secretHint }}</code>
                            </div>
                            <p class="text-xs text-(--color-on-surface-muted)">{!! $secretHintText !!}</p>
                            <button wire:click="$set('secretHint', '')" class="text-xs text-(--color-on-surface-muted) underline hover:text-(--color-on-surface)">
                                Update secret
                            </button>
                        @else
                            <p class="text-xs text-(--color-on-surface-muted)">{!! $secretHintText !!}</p>
                            <div class="flex items-center gap-2">
                                <input
                                    type="password"
                                    wire:model="pasteSecretValue"
                                    placeholder="Paste your {{ $secretLabel }}…"
                                    class="w-full rounded-lg border border-(--color-theme-border) bg-(--color-surface-alt) py-2 pl-3 pr-3 font-mono text-xs text-(--color-on-surface) focus:border-primary-400 focus:outline-none focus:ring-1 focus:ring-primary-400"
                                />
                                <button wire:click="savePastedSecret"
                                    wire:loading.attr="disabled"
                                    wire:target="savePastedSecret"
                                    class="shrink-0 rounded-lg bg-primary-600 px-3 py-2 text-xs font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="savePastedSecret">Save</span>
                                    <span wire:loading wire:target="savePastedSecret">Saving…</span>
                                </button>
                            </div>
                            @error('pasteSecretValue')
                                <p class="text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        @endif
                    @endif
                </div>

                {{-- Setup Guide (collapsible) --}}
                <div
                    x-data="{ open: {{ !$secretHint ? 'true' : 'false' }} }"
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
                                    'Set <strong>Payload URL</strong> to the Webhook URL above.',
                                    'Set <strong>Content type</strong> to <code class="rounded bg-(--color-surface-alt) px-1">application/json</code>.',
                                    'Set <strong>Secret</strong> to the value shown in the Signing Secret section above.',
                                    'Under <strong>Which events?</strong>, select individual events: <em>Issues, Pull requests, Pushes, Workflow runs, Releases</em>.',
                                    'Click <strong>Add webhook</strong>. GitHub sends a ping event immediately — you should see your first signal.',
                                ],
                                'slack' => [
                                    'Go to <strong>api.slack.com/apps</strong> → Create or select your Slack app.',
                                    'Under <strong>Event Subscriptions</strong>, enable Events and set <strong>Request URL</strong> to the Webhook URL above.',
                                    'Under <strong>Subscribe to bot events</strong>, add: <code class="rounded bg-(--color-surface-alt) px-1">message.channels</code>, <code class="rounded bg-(--color-surface-alt) px-1">app_mention</code>.',
                                    'Copy the <strong>Signing Secret</strong> from <strong>Basic Information → App Credentials</strong> and paste it into the Signing Secret field above.',
                                    'Under <strong>OAuth &amp; Permissions</strong>, add scopes: <code class="rounded bg-(--color-surface-alt) px-1">channels:history</code>, <code class="rounded bg-(--color-surface-alt) px-1">channels:read</code>.',
                                    'Install the app to your workspace and invite the bot to channels you want to monitor.',
                                ],
                                'jira' => [
                                    'Go to <strong>Jira Admin → System → WebHooks → Create a WebHook</strong>.',
                                    'Set <strong>URL</strong> to the Webhook URL above.',
                                    'Set <strong>Secret</strong> to the value shown in the Signing Secret section above.',
                                    'Under <strong>Events</strong>, enable: <em>Issue created, Issue updated</em>.',
                                    'Click <strong>Create</strong>.',
                                ],
                                'linear' => [
                                    'Go to <strong>Linear Settings → API → Webhooks → New webhook</strong>.',
                                    'Set <strong>URL</strong> to the Webhook URL above.',
                                    'Set the <strong>Signing secret</strong> to the value shown in the Signing Secret section above.',
                                    'Enable resource types: <strong>Issue</strong>.',
                                    'Click <strong>Create webhook</strong>.',
                                ],
                                'discord' => [
                                    'Go to your Discord server settings → <strong>Integrations → Webhooks → New Webhook</strong>.',
                                    'Set the <strong>Webhook URL</strong> field to the Webhook URL above.',
                                    'Configure your bot to sign requests with <code class="rounded bg-(--color-surface-alt) px-1">X-Webhook-Signature</code> using the secret above.',
                                    'Click <strong>Save</strong>.',
                                ],
                                'sentry' => [
                                    'Go to <strong>Sentry Organization Settings → Developer Settings → Internal Integrations</strong>.',
                                    'Create a new integration and add the Webhook URL above as a webhook endpoint.',
                                    'Set the <strong>Token</strong> / <strong>Client Secret</strong> to the value shown above.',
                                    'Enable events: <strong>issue, error</strong>.',
                                ],
                                'pagerduty' => [
                                    'Go to <strong>PagerDuty → Integrations → Generic Webhooks (V3)</strong>.',
                                    'Add the Webhook URL above as a new webhook subscription.',
                                    'Set <strong>Auth Token</strong> to the value shown in the Signing Secret section above.',
                                    'Select event types: <strong>incident.triggered, incident.resolved</strong>.',
                                ],
                                'datadog' => [
                                    'Go to <strong>Datadog → Integrations → Webhooks → New Webhook</strong>.',
                                    'Set <strong>URL</strong> to the Webhook URL above.',
                                    'Under <strong>Custom Headers</strong>, add: <code class="rounded bg-(--color-surface-alt) px-1">X-Datadog-Webhook-Secret: {secret}</code> replacing <code>{secret}</code> with the value shown above.',
                                    'Select the events to forward: <em>monitor alert, monitor recovery</em>.',
                                    'Click <strong>Save</strong>.',
                                ],
                                'whatsapp' => [
                                    'Go to <strong>Meta for Developers → Your App → WhatsApp → Configuration</strong>.',
                                    'Set <strong>Callback URL</strong> to the Webhook URL above.',
                                    'Copy the <strong>App Secret</strong> from <strong>Settings → Basic</strong> and paste it into the App Secret field above.',
                                    'Under <strong>Webhook Fields</strong>, subscribe to: <strong>messages</strong>.',
                                ],
                                'clearcue' => [
                                    'Go to your <strong>ClearCue dashboard → Settings → Webhooks</strong>.',
                                    'Set <strong>Endpoint URL</strong> to the Webhook URL above.',
                                    'Set <strong>Signing secret</strong> to the value shown in the Signing Secret section above.',
                                    'Select the signal types to forward.',
                                    'Click <strong>Save webhook</strong>.',
                                ],
                                'webhook' => [
                                    'Configure your service to <strong>POST</strong> JSON to the Webhook URL above.',
                                    'Add the header <code class="rounded bg-(--color-surface-alt) px-1">X-Webhook-Signature: {hmac}</code> where <code>{hmac}</code> is <code>HMAC-SHA256(body, secret)</code> using the secret above.',
                                    'Test by sending a sample payload — use "Verify Connection" below to confirm signals arrive.',
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
                                {{ $recentSignalCount > 0 ? '✓ ' . $recentSignalCount . ' ' . Str::plural('signal', $recentSignalCount) . ' in the last hour' : 'No signals in the last hour' }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Self-hosted / .env info (collapsible, for developers) --}}
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-center gap-2 text-xs text-(--color-on-surface-muted) hover:text-(--color-on-surface)">
                        <svg class="h-3.5 w-3.5 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        Self-hosted / environment variable configuration
                    </summary>
                    <div class="mt-3 rounded-lg border border-(--color-theme-border) bg-(--color-surface-alt) p-3 text-xs text-(--color-on-surface-muted) space-y-1">
                        <p>In self-hosted deployments you can also configure a shared secret via <code class="rounded bg-white/60 px-1 font-mono">.env</code>.</p>
                        <p>The per-team DB secret (shown above) takes precedence over the environment variable for per-team webhook URLs.</p>
                        <p class="mt-1">For legacy single-team endpoints (<code class="rounded bg-white/60 px-1 font-mono">POST /api/signals/{{ $driver }}</code>), the environment variable is still used.</p>
                    </div>
                </details>

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
