<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    @if ($testMessage)
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ $testMessage }}</div>
    @endif

    @if ($testError)
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ $testError }}</div>
    @endif

    <div class="space-y-6">

        {{-- Status bar --}}
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-6 py-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $isActive ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                    <span class="text-sm font-medium text-gray-900">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                </div>
                @if ($lastTestedAt)
                    <span class="text-sm text-gray-500">
                        Last tested {{ $lastTestedAt }}
                        <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $lastTestStatus === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $lastTestStatus }}
                        </span>
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-sm text-gray-700">Enabled</span>
                </label>
                <button wire:click="testConnection" wire:loading.attr="disabled"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </button>
            </div>
        </div>

        {{-- API Credentials --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Meta WhatsApp Cloud API Credentials</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Obtain these from your
                    <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800">Meta App Dashboard</a>
                    under WhatsApp &rarr; API Setup.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <x-form-input
                    wire:model="phoneNumberId"
                    label="Phone Number ID"
                    placeholder="123456789012345"
                    autocomplete="off"
                >
                    <x-slot:hint>Numeric ID of the WhatsApp sender phone number (from Meta App Dashboard).</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="accessToken"
                    type="password"
                    label="Access Token"
                    placeholder="{{ $phoneNumberId ? '(leave blank to keep existing)' : '' }}"
                    autocomplete="new-password"
                >
                    <x-slot:hint>Permanent system user token with whatsapp_business_messaging permission.</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="businessAccountId"
                    label="Business Account ID"
                    placeholder="987654321098765"
                    autocomplete="off"
                >
                    <x-slot:hint>WhatsApp Business Account ID — used for webhook registration reference.</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="verifyToken"
                    label="Webhook Verify Token"
                    placeholder="my-secret-verify-token"
                    autocomplete="off"
                >
                    <x-slot:hint>
                        Token you set when registering the delivery receipt webhook in Meta.
                        Your webhook URL is: <code class="rounded bg-gray-100 px-1 text-xs">{{ url('/api/whatsapp/webhook/') }}{teamId}</code>
                    </x-slot:hint>
                </x-form-input>
            </div>
        </div>

        {{-- Template Messages --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Template Messages</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    To send a WhatsApp-approved template message, include
                    <code class="rounded bg-gray-100 px-1 text-xs font-mono">whatsapp_template</code>
                    in the proposal's metadata. Example:
                </p>
            </div>
            <div class="p-6">
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700">{{ '{' }}
  "whatsapp_template": {
    "name": "order_confirmation",
    "language": "en_US",
    "components": [
      {
        "type": "body",
        "parameters": [{ "type": "text", "text": "John" }]
      }
    ]
  }
{{ '}' }}</pre>
                <p class="mt-3 text-sm text-gray-500">
                    Templates must be approved in
                    <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800">WhatsApp Manager</a>
                    before use. Without <code class="rounded bg-gray-100 px-1 text-xs font-mono">whatsapp_template</code>, a plain text message is sent.
                </p>
            </div>
        </div>

        {{-- Save --}}
        <div class="flex justify-end">
            <button wire:click="save" wire:loading.attr="disabled"
                class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Save Configuration</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

    </div>
</div>
