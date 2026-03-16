<div class="max-w-xl">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Two-Factor Authentication</h3>
                    <p class="mt-1 text-sm text-gray-500">Add extra security using an authenticator app (TOTP).</p>
                </div>
                @if($state === 'enabled')
                    <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700">Enabled</span>
                @elseif($state === 'enabling')
                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">Pending setup</span>
                @else
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500">Disabled</span>
                @endif
            </div>
        </div>

        <div class="px-6 py-5">
            @if($state === 'disabled')
                <p class="mb-4 text-sm text-gray-600">
                    Protect your account with a one-time code from an authenticator app like Google Authenticator or 1Password.
                </p>
                <button wire:click="enableTwoFactor"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                    Enable Two-Factor Authentication
                </button>

            @elseif($state === 'enabling')
                <div class="space-y-5">
                    <p class="text-sm text-gray-600">
                        Scan this QR code with your authenticator app, then enter the 6-digit code to confirm setup.
                    </p>

                    @if($qrCodeSvg)
                        <div class="inline-block rounded-xl border border-gray-200 bg-white p-3">
                            {!! $qrCodeSvg !!}
                        </div>
                    @endif

                    <form wire:submit="confirmTwoFactor" class="space-y-4">
                        <x-form-input
                            wire:model="confirmationCode"
                            label="Authentication Code"
                            type="text"
                            id="confirmation_code"
                            placeholder="000000"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            required
                            :error="$confirmationError ?: null"
                        />
                        <div class="flex gap-3">
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                                Confirm
                            </button>
                            <button type="button"
                                    wire:click="disableTwoFactor"
                                    class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>

            @elseif($state === 'enabled')
                <div class="space-y-5">
                    <p class="text-sm text-gray-600">
                        Two-factor authentication is active. You'll be asked for a code when you sign in.
                    </p>

                    {{-- Recovery codes --}}
                    @if($showingRecoveryCodes && count($recoveryCodes) > 0)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <p class="mb-3 text-xs font-semibold text-amber-800 uppercase tracking-wide">Recovery Codes</p>
                            <p class="mb-3 text-xs text-amber-700">
                                Store these codes in a safe place. Each can only be used once to access your account if you lose your authenticator device.
                            </p>
                            <div class="grid grid-cols-2 gap-1">
                                @foreach($recoveryCodes as $code)
                                    <code class="font-mono text-xs text-amber-900">{{ $code }}</code>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <button wire:click="regenerateRecoveryCodes"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            {{ $showingRecoveryCodes ? 'Regenerate' : 'Show' }} Recovery Codes
                        </button>
                        <button wire:click="disableTwoFactor"
                                wire:confirm="Disable two-factor authentication? Your account will be less secure."
                                class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                            Disable 2FA
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
