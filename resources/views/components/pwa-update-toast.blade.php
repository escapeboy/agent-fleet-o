{{--
    PWA Update Toast
    Appears when a new service worker version is available.
    Triggered by the `fleetq:sw-update-ready` custom event from push-notifications.js.
--}}
<div
    x-data="{
        updateReady: false,

        init() {
            window.addEventListener('fleetq:sw-update-ready', () => {
                this.updateReady = true;
            });
        },

        reload() {
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'SKIP_WAITING' });
            }
            window.location.reload();
        }
    }"
    x-show="updateReady"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="fixed bottom-4 left-4 z-50 flex items-center gap-3 rounded-xl border border-gray-700 bg-gray-900 px-4 py-3 shadow-2xl"
    role="alert"
>
    <svg class="h-5 w-5 flex-shrink-0 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    <span class="text-sm text-gray-200">New version available.</span>
    <button
        @click="reload()"
        class="ml-1 rounded-lg bg-primary-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-primary-700"
    >
        Refresh
    </button>
</div>
