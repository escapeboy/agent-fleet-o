<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="space-y-6">

        {{-- Status bar --}}
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-6 py-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $isActive ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                    <span class="text-sm font-medium text-gray-900">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                </div>
            </div>
            <label class="flex cursor-pointer items-center gap-2">
                <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                <span class="text-sm text-gray-700">Enabled</span>
            </label>
        </div>

        {{-- How it works --}}
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-6 py-4">
            <h3 class="text-sm font-semibold text-blue-900">How In-App Notifications Work</h3>
            <p class="mt-1 text-sm text-blue-700">
                When enabled, experiment results and outbound messages are delivered as platform notifications
                to your team members. Notifications appear in the notification bell and the notification inbox.
                No external service configuration is required.
            </p>
        </div>

        {{-- Settings --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Notification Settings</h2>
                <p class="mt-0.5 text-sm text-gray-500">Configure how in-app notifications are delivered to team members.</p>
            </div>
            <div class="space-y-5 p-6">
                <div>
                    <x-form-checkbox wire:model="notifyAllMembers" label="Notify all team members" />
                    <p class="ml-6 mt-0.5 text-xs text-gray-500">When enabled, all team members receive outbound notifications. Otherwise, only the team owner is notified.</p>
                </div>
                <div class="max-w-xs">
                    <x-form-select wire:model="defaultPriority" label="Default Priority">
                        <option value="low">Low</option>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                    </x-form-select>
                </div>
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
