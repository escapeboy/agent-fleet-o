<div>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">New Broadcast</h2>
                <p class="mt-0.5 text-sm text-gray-500">A one-time mass email sent to every subscribed member of an audience.</p>
            </div>

            <div class="space-y-5 p-6">
                <x-form-select wire:model.live="audienceId" label="Audience">
                    <option value="">Select an audience…</option>
                    @foreach ($audiences as $audience)
                        <option value="{{ $audience->id }}">{{ $audience->name }}</option>
                    @endforeach
                </x-form-select>

                @if ($estimate)
                    <div class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">
                        <span class="font-medium text-gray-900">{{ $estimate['recipients'] }}</span> subscribed recipient(s)
                        · est. <span class="font-medium text-gray-900">{{ $estimate['estimated_credits'] }}</span> credit(s)
                        <span class="text-gray-400">(cap {{ $estimate['max_recipients'] }})</span>
                        @if ($estimate['recipients'] > $estimate['max_recipients'])
                            <span class="ml-2 font-medium text-red-600">Exceeds recipient cap.</span>
                        @endif
                    </div>
                @endif

                <x-form-input wire:model="name" label="Name" placeholder="June newsletter" />
                <x-form-input wire:model="subject" label="Subject" placeholder="What's new this month" />
                <x-form-textarea wire:model="body" label="Body" hint="HTML is supported." rows="10" />
            </div>

            <div class="flex justify-end border-t border-gray-200 px-6 py-4">
                <button wire:click="create" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Create Broadcast
                </button>
            </div>
        </div>
    </div>
</div>
