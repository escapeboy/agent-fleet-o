<div class="max-w-xl">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Profile Information</h3>
            <p class="mt-1 text-sm text-gray-500">Update your name and email address.</p>
        </div>

        <form wire:submit="save" class="px-6 py-5 space-y-4">
            @if(session()->has('profile_saved'))
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    Profile updated successfully.
                </div>
            @endif

            @if($emailChanged)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    A verification link has been sent to your new email address.
                </div>
            @endif

            <x-form-input
                wire:model="name"
                label="Name"
                type="text"
                id="name"
                required
                :error="$errors->updateProfileInformation->first('name')"
            />

            <x-form-input
                wire:model="email"
                label="Email"
                type="email"
                id="email"
                required
                :error="$errors->updateProfileInformation->first('email')"
            />

            <div class="flex justify-end pt-2">
                <button type="submit"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                    <span wire:loading.remove>Save</span>
                    <span wire:loading>Saving…</span>
                </button>
            </div>
        </form>
    </div>
</div>
