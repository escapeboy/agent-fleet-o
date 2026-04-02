{{--
    PWA Install Prompt
    Shown when the browser fires `beforeinstallprompt`.
    Respects a 7-day dismissal stored in localStorage.
    Auto-hides on iOS (no install prompt API) and when already installed (standalone mode).
--}}
<div
    x-data="{
        deferredPrompt: null,
        canInstall: false,
        isInstalled: window.matchMedia('(display-mode: standalone)').matches
                     || window.navigator.standalone === true,

        init() {
            if (this.isInstalled) return;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                this.deferredPrompt = e;
                this.canInstall = true;
            });

            window.addEventListener('appinstalled', () => {
                this.isInstalled = true;
                this.canInstall = false;
                this.deferredPrompt = null;
            });
        },

        async install() {
            if (!this.deferredPrompt) return;
            this.deferredPrompt.prompt();
            const { outcome } = await this.deferredPrompt.userChoice;
            this.deferredPrompt = null;
            this.canInstall = false;
            if (outcome === 'accepted') {
                this.isInstalled = true;
            }
        },

        dismiss() {
            this.canInstall = false;
            localStorage.setItem('fleetq-pwa-dismissed', Date.now());
        },

        get shouldShow() {
            if (this.isInstalled || !this.canInstall) return false;
            const dismissed = localStorage.getItem('fleetq-pwa-dismissed');
            if (dismissed && (Date.now() - Number(dismissed)) < 7 * 24 * 60 * 60 * 1000) return false;
            return true;
        }
    }"
    x-show="shouldShow"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    class="fixed bottom-4 right-4 z-50 w-80 rounded-xl border border-gray-700 bg-gray-900 p-4 shadow-2xl"
    role="dialog"
    aria-label="Install FleetQ"
>
    <div class="flex items-start gap-3">
        <img src="/icons/icon-192.png" alt="FleetQ" class="h-12 w-12 flex-shrink-0 rounded-xl">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-white">Install FleetQ</p>
            <p class="mt-0.5 text-xs text-gray-400">Add to your home screen for instant access.</p>
        </div>
        <button
            @click="dismiss()"
            class="flex-shrink-0 rounded p-0.5 text-gray-500 transition hover:text-gray-300"
            aria-label="Dismiss"
        >
            <i class="fa-solid fa-xmark text-base"></i>
        </button>
    </div>
    <div class="mt-3 flex gap-2">
        <button
            @click="install()"
            class="flex-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-primary-700"
        >
            Install
        </button>
        <button
            @click="dismiss()"
            class="flex-1 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm font-medium text-gray-300 transition hover:bg-gray-700"
        >
            Not now
        </button>
    </div>
</div>
