<div>
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto" aria-modal="true" role="dialog">
            {{-- Backdrop --}}
            <div class="fixed inset-0 bg-black/50 transition-opacity" wire:click="$set('showModal', false)"></div>

            {{-- Modal --}}
            <div class="relative z-10 w-full max-w-lg rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6 shadow-xl mx-4">
                {{-- Header --}}
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-(--color-on-surface)">{{ $channelLabel }}</h3>
                        <p class="mt-0.5 text-sm text-(--color-on-surface-muted)">{{ $description }}</p>
                    </div>
                    <button wire:click="$set('showModal', false)" class="rounded-lg p-1 text-(--color-on-surface-muted) hover:bg-(--color-surface-alt) hover:text-(--color-on-surface)">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    {{-- Telegram --}}
                    @if($channel === 'telegram')
                        <x-form-input wire:model="botToken" label="Bot Token" type="password" placeholder="123456:ABC-DEF..."
                            hint="Get your token from @BotFather on Telegram." :error="$errors->first('botToken')" />
                    @endif

                    {{-- Slack --}}
                    @if($channel === 'slack')
                        <x-form-input wire:model="webhookUrl" label="Webhook URL" type="url" placeholder="https://hooks.slack.com/services/..."
                            hint="Create an incoming webhook in your Slack workspace settings." :error="$errors->first('webhookUrl')" />
                    @endif

                    {{-- Discord --}}
                    @if($channel === 'discord')
                        <x-form-input wire:model="webhookUrl" label="Webhook URL" type="url" placeholder="https://discord.com/api/webhooks/..."
                            hint="Server Settings > Integrations > Webhooks." :error="$errors->first('webhookUrl')" />
                    @endif

                    {{-- Microsoft Teams --}}
                    @if($channel === 'teams')
                        <x-form-input wire:model="webhookUrl" label="Webhook URL" type="url" placeholder="https://prod-XX.westus.logic.azure.com/..."
                            hint="Create a Power Automate Workflows webhook for your Teams channel." :error="$errors->first('webhookUrl')" />
                    @endif

                    {{-- Google Chat --}}
                    @if($channel === 'google_chat')
                        <x-form-input wire:model="webhookUrl" label="Webhook URL" type="url" placeholder="https://chat.googleapis.com/v1/spaces/..."
                            hint="Space Settings > Apps & integrations > Webhooks." :error="$errors->first('webhookUrl')" />
                    @endif

                    {{-- WhatsApp --}}
                    @if($channel === 'whatsapp')
                        <x-form-input wire:model="phoneNumberId" label="Phone Number ID" type="text" placeholder="1234567890"
                            hint="From Meta Developer Console > WhatsApp > API Setup." :error="$errors->first('phoneNumberId')" />
                        <x-form-input wire:model="accessToken" label="Access Token" type="password" placeholder="EAAx..."
                            :error="$errors->first('accessToken')" />
                    @endif

                    {{-- Email (SMTP) --}}
                    @if($channel === 'email')
                        <p class="text-sm text-(--color-on-surface-muted) -mt-1">
                            These credentials are used by your agents to send outbound emails.
                            Platform system emails (invitations, notifications) are sent separately and do not use this configuration.
                        </p>
                        <div class="grid grid-cols-2 gap-4">
                            <x-form-input wire:model="smtpHost" label="SMTP Host" type="text" placeholder="smtp.gmail.com"
                                :error="$errors->first('smtpHost')" />
                            <x-form-input wire:model="smtpPort" label="Port" type="number" min="1" max="65535"
                                :error="$errors->first('smtpPort')" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <x-form-input wire:model="smtpUsername" label="Username" type="text" placeholder="user@example.com"
                                :error="$errors->first('smtpUsername')" />
                            <x-form-input wire:model="smtpPassword" label="Password" type="password"
                                :error="$errors->first('smtpPassword')" />
                        </div>
                        <x-form-select wire:model="smtpEncryption" label="Encryption">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="">None</option>
                        </x-form-select>
                        <div class="grid grid-cols-2 gap-4">
                            <x-form-input wire:model="fromAddress" label="From Address" type="email" placeholder="noreply@example.com"
                                :error="$errors->first('fromAddress')" />
                            <x-form-input wire:model="fromName" label="From Name" type="text" placeholder="FleetQ"
                                :error="$errors->first('fromName')" />
                        </div>
                    @endif

                    {{-- Webhook (generic) --}}
                    @if($channel === 'webhook')
                        <x-form-input wire:model="defaultUrl" label="Webhook URL" type="url" placeholder="https://your-app.com/webhook"
                            hint="Target URL for HTTP POST requests." :error="$errors->first('defaultUrl')" />
                        <x-form-input wire:model="secret" label="Signing Secret (optional)" type="password" placeholder="your-secret-key"
                            hint="Used for HMAC-SHA256 payload signatures (X-Webhook-Signature header)." :error="$errors->first('secret')" />
                    @endif

                    {{-- Ntfy --}}
                    @if($channel === 'ntfy')
                        <x-form-input wire:model="ntfyBaseUrl" label="Base URL" type="url" placeholder="https://ntfy.sh"
                            hint="Your ntfy server URL. Use https://ntfy.sh for the public server." :error="$errors->first('ntfyBaseUrl')" />
                        <x-form-input wire:model="ntfyTopic" label="Topic" type="text" placeholder="fleetq-alerts"
                            hint="Topic name to publish notifications to." :error="$errors->first('ntfyTopic')" />
                        <x-form-select wire:model="ntfyPriority" label="Default Priority">
                            <option value="min">Min</option>
                            <option value="low">Low</option>
                            <option value="default">Default</option>
                            <option value="high">High</option>
                            <option value="max">Max</option>
                        </x-form-select>
                        <x-form-input wire:model="ntfyTags" label="Default Tags (optional)" type="text" placeholder="rotating_light,warning"
                            hint="Comma-separated emoji shortcode tags shown in notifications." :error="$errors->first('ntfyTags')" />
                        <x-form-input wire:model="ntfyToken" label="Access Token (optional)" type="password" placeholder="tk_..."
                            hint="Bearer token for private topics. Leave empty for public topics." :error="$errors->first('ntfyToken')" />
                    @endif

                    {{-- Validation error --}}
                    @error('credentials')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    {{-- Test result --}}
                    @if($testResult)
                        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-800">
                            {{ $testResult }}
                        </div>
                    @endif

                    @if($testError)
                        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
                            {{ $testError }}
                        </div>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="mt-6 flex items-center justify-between">
                    <div>
                        @if($hasExistingConfig)
                            <button wire:click="disconnect" wire:confirm="Remove this connector configuration? The channel will become inactive."
                                class="text-sm text-red-600 hover:text-red-800">
                                Remove Configuration
                            </button>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="testConnection" wire:loading.attr="disabled"
                            class="rounded-lg border border-(--color-theme-border-strong) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt) disabled:opacity-50">
                            <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                            <span wire:loading wire:target="testConnection">Testing...</span>
                        </button>
                        <button wire:click="save" wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading wire:target="save">Saving...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
