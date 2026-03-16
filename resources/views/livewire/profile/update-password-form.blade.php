<div class="max-w-xl">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Password</h3>
            <p class="mt-1 text-sm text-gray-500">
                @if($hasPassword)
                    Change your account password.
                @else
                    Set a password to enable direct email/password login.
                @endif
            </p>
        </div>

        @if(session()->has('password_saved'))
            <div class="mx-6 mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                Password {{ $hasPassword ? 'updated' : 'set' }} successfully.
            </div>
        @endif

        @if($hasPassword)
            {{-- Change existing password --}}
            <form wire:submit="updatePassword" class="px-6 py-5 space-y-4">
                <x-form-input
                    wire:model="currentPassword"
                    label="Current Password"
                    type="password"
                    id="current_password"
                    required
                    autocomplete="current-password"
                    :error="$errors->first('current_password')"
                />
                <x-form-input
                    wire:model="password"
                    label="New Password"
                    type="password"
                    id="password"
                    required
                    autocomplete="new-password"
                    :error="$errors->first('password')"
                />
                <x-form-input
                    wire:model="passwordConfirmation"
                    label="Confirm New Password"
                    type="password"
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <div class="flex justify-end pt-2">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                        <span wire:loading.remove>Update Password</span>
                        <span wire:loading>Updating…</span>
                    </button>
                </div>
            </form>
        @else
            {{-- Set initial password --}}
            <form wire:submit="setInitialPassword" class="px-6 py-5 space-y-4">
                <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                    You're signed in via a social provider. Setting a password lets you also log in with your email.
                </div>

                <x-form-input
                    wire:model="password"
                    label="New Password"
                    type="password"
                    id="password"
                    required
                    autocomplete="new-password"
                    :error="$errors->first('password')"
                />
                <x-form-input
                    wire:model="passwordConfirmation"
                    label="Confirm Password"
                    type="password"
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <div class="flex justify-end pt-2">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                        <span wire:loading.remove>Set Password</span>
                        <span wire:loading>Setting…</span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
