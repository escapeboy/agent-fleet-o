<div>
    @if($webauthnEnabled)
        @if(session()->has('passkey_message'))
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
                {{ session('passkey_message') }}
            </div>
        @endif

        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Passkeys</h3>
                <p class="mt-0.5 text-sm text-gray-500">Use Face ID, Touch ID, or a hardware security key to sign in without a password.</p>
            </div>
        </div>

        {{-- Existing passkeys --}}
        @if($passkeys->isNotEmpty())
            <div class="mb-4 space-y-2">
                @foreach($passkeys as $key)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-key text-lg text-gray-400"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $key->name }}</p>
                                <p class="text-xs text-gray-500">Added {{ $key->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                        <button wire:click="deletePasskey('{{ $key->id }}')"
                            wire:confirm="Remove this passkey?"
                            class="rounded-md px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                            Remove
                        </button>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mb-4 text-sm text-gray-500">No passkeys registered yet.</p>
        @endif

        {{-- Register new passkey --}}
        <div x-data="passkeyRegister" class="space-y-3">
            @if(!$passkeys->count())
            <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                <strong>Tip:</strong> Add a passkey to enable biometric login (Touch ID, Face ID, Windows Hello) on this device.
            </div>
            @endif

            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-600">Key label <span class="text-gray-400">(optional)</span></label>
                    <input x-model="keyName" type="text" placeholder="e.g. MacBook Touch ID"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                </div>
                <button @click="register()" :disabled="loading || !supported"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span x-show="!loading">Add Passkey</span>
                    <span x-show="loading">Registering…</span>
                </button>
            </div>

            <template x-if="error">
                <p class="text-sm text-red-600" x-text="error"></p>
            </template>

            <template x-if="!supported">
                <p class="text-xs text-gray-400">Your browser does not support passkeys.</p>
            </template>
        </div>
    @endif
</div>
