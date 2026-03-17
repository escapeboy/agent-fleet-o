<div class="w-full max-w-lg">
    <div class="mb-8 text-center">
        <div class="mb-3 flex justify-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600">
                <x-logo-icon class="h-7 w-7 text-white" />
            </div>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ config('app.name') }}</h1>
        <p class="mt-2 text-sm text-gray-500">AI Agent Mission Control</p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-8">
        <div class="mb-6 flex items-start gap-3">
            <div class="flex-shrink-0 rounded-lg bg-amber-50 p-2">
                <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Updated Terms of Service</h2>
                <p class="mt-1 text-sm text-gray-500">Version {{ $version }} — please review and accept to continue.</p>
            </div>
        </div>

        <div class="mb-6 rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
            <p class="leading-relaxed">
                By continuing to use {{ config('app.name') }}, you agree to our
                <a href="/terms" target="_blank" class="font-medium text-primary-600 hover:text-primary-700 hover:underline">Terms of Service</a>
                and
                <a href="/privacy" target="_blank" class="font-medium text-primary-600 hover:text-primary-700 hover:underline">Privacy Policy</a>.
                These documents describe how we collect, use, and protect your data.
            </p>
        </div>

        <div class="flex flex-col gap-3">
            <button
                wire:click="accept"
                wire:loading.attr="disabled"
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="accept">I agree — continue to {{ config('app.name') }}</span>
                <span wire:loading wire:target="accept">Saving…</span>
            </button>

            <button
                wire:click="decline"
                type="button"
                class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2"
            >
                Decline and sign out
            </button>
        </div>
    </div>
</div>
