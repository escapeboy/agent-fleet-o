// FleetQ PWA Features — Wake Lock, Web Share, Speech, Network Info, View Transitions,
//                       File Handling, Background Fetch, Persistent Storage
// All features are feature-detected and gracefully degrade on unsupported browsers.

// ─── Wake Lock API ──────────────────────────────────────────────────────────
// Prevents screen from locking during active experiment/crew monitoring.
document.addEventListener('alpine:init', () => {
    Alpine.data('wakeLock', () => ({
        lock: null,
        supported: 'wakeLock' in navigator,

        async acquire() {
            if (!this.supported) return;
            try {
                this.lock = await navigator.wakeLock.request('screen');
                this.lock.addEventListener('release', () => {
                    this.lock = null;
                });
            } catch (err) {
                // NotAllowedError: battery saver mode or document not visible — safe to ignore
            }
        },

        release() {
            this.lock?.release();
            this.lock = null;
        },

        init() {
            // CRITICAL: Wake Lock auto-releases when tab is hidden — must re-acquire on focus
            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'visible' && this.supported && !this.lock) {
                    await this.acquire();
                }
            });
        },
    }));

    // ─── Web Share API ──────────────────────────────────────────────────────
    // Native OS share sheet. Falls back to clipboard copy on unsupported browsers.
    Alpine.data('webShare', () => ({
        canShare: 'share' in navigator,
        copied: false,

        async share(title, text, url) {
            if (this.canShare) {
                try {
                    await navigator.share({ title, text, url });
                } catch (err) {
                    // AbortError = user dismissed — not an error worth reporting
                    if (err.name !== 'AbortError') console.warn('[FleetQ] Share failed:', err);
                }
            } else {
                // Clipboard fallback
                await this.copyToClipboard(url);
            }
        },

        async copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            } catch (err) {
                console.warn('[FleetQ] Clipboard write failed:', err);
            }
        },
    }));

    // ─── Network Status ─────────────────────────────────────────────────────
    // Exposes online/offline state and connection quality.
    // navigator.connection is Chrome/Android only — navigator.onLine works everywhere.
    Alpine.data('networkStatus', () => ({
        isOnline: navigator.onLine,
        effectiveType: navigator.connection?.effectiveType ?? '4g',
        saveData: navigator.connection?.saveData ?? false,

        get isSlow() {
            return !this.isOnline || ['slow-2g', '2g'].includes(this.effectiveType) || this.saveData;
        },

        init() {
            window.addEventListener('online',  () => { this.isOnline = true; });
            window.addEventListener('offline', () => { this.isOnline = false; });

            if (navigator.connection) {
                navigator.connection.addEventListener('change', () => {
                    this.effectiveType = navigator.connection.effectiveType;
                    this.saveData = navigator.connection.saveData;
                });
            }
        },
    }));

    // ─── Speech Recognition ─────────────────────────────────────────────────
    // Voice input for AssistantPanel. Chrome/Edge only; hidden on Firefox/Safari.
    const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

    Alpine.data('speechInput', () => ({
        isSupported: !!SpeechRecognitionAPI,
        isListening: false,
        transcript: '',
        interimTranscript: '',
        recognition: null,

        init() {
            if (!this.isSupported) return;

            this.recognition = new SpeechRecognitionAPI();
            this.recognition.continuous = false;
            this.recognition.interimResults = true;
            this.recognition.lang = document.documentElement.lang || 'en-US';

            this.recognition.addEventListener('result', (e) => {
                let final = '';
                let interim = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const t = e.results[i][0].transcript;
                    e.results[i].isFinal ? (final += t) : (interim += t);
                }
                if (final) this.transcript = final;
                this.interimTranscript = interim;
            });

            this.recognition.addEventListener('end', () => {
                this.isListening = false;
                this.interimTranscript = '';
                if (this.transcript) {
                    this.$dispatch('speech-result', { text: this.transcript });
                    this.transcript = '';
                }
            });

            this.recognition.addEventListener('error', (e) => {
                this.isListening = false;
                this.interimTranscript = '';
                if (e.error !== 'aborted') console.warn('[FleetQ] Speech error:', e.error);
            });
        },

        toggle() {
            if (!this.isSupported) return;
            if (this.isListening) {
                this.recognition.stop();
            } else {
                this.transcript = '';
                this.recognition.start();
                this.isListening = true;
            }
        },
    }));
});

// ─── View Transitions API ──────────────────────────────────────────────────
// App-like page transitions for wire:navigate. Livewire 4.5+ may handle this
// natively — this is a safe enhancement for older 4.x versions.
(function initViewTransitions() {
    if (!document.startViewTransition) return;

    document.addEventListener('livewire:navigate', (event) => {
        if (!document.startViewTransition) return;
        event.preventDefault();
        document.startViewTransition(async () => {
            await event.detail.visit();
        });
    });
})();

// ─── File Handling API ─────────────────────────────────────────────────────
// Register FleetQ as a handler for .json and .yaml files (Chrome/Edge only).
// The manifest file_handlers entry routes to /workflows/create.
(function initFileHandler() {
    if (!('launchQueue' in window)) return;

    // Wait for Livewire to initialize before dispatching file-imported events
    document.addEventListener('livewire:initialized', () => {
        window.launchQueue.setConsumer(async (launchParams) => {
            if (!launchParams.files?.length) return;

            for (const fileHandle of launchParams.files) {
                try {
                    const file = await fileHandle.getFile();
                    const content = await file.text();
                    // Dispatch to Livewire (WorkflowBuilderPage listens for this event)
                    Livewire.dispatch('file-imported', { content, name: file.name, type: file.type });
                } catch (err) {
                    console.warn('[FleetQ] File handler error:', err);
                }
            }
        });
    });
})();

// ─── Background Fetch API ──────────────────────────────────────────────────
// Large artifact downloads that survive tab closure (Chrome/Edge only).
window.FleetQArtifactDownload = {
    async download(artifactId, filename, sizeBytes = 0) {
        if (!navigator.serviceWorker || !('backgroundFetch' in ServiceWorkerRegistration.prototype)) {
            return this.regularDownload(artifactId, filename);
        }

        try {
            const reg = await navigator.serviceWorker.ready;

            // Avoid tag collision — don't start if already in progress
            const existing = await reg.backgroundFetch.get(`artifact-${artifactId}`);
            if (existing) {
                window.dispatchEvent(new CustomEvent('artifact-download-in-progress', { detail: { artifactId } }));
                return;
            }

            const bgFetch = await reg.backgroundFetch.fetch(
                `artifact-${artifactId}`,
                [`/api/v1/artifacts/${artifactId}/download`],
                {
                    title: `Downloading ${filename}`,
                    icons: [{ src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' }],
                    downloadTotal: sizeBytes,
                }
            );

            bgFetch.addEventListener('progress', () => {
                if (!bgFetch.downloadTotal) return;
                const percent = Math.round((bgFetch.downloaded / bgFetch.downloadTotal) * 100);
                window.dispatchEvent(new CustomEvent('artifact-download-progress', {
                    detail: { artifactId, percent },
                }));
            });
        } catch (err) {
            console.warn('[FleetQ] Background fetch failed, falling back:', err);
            return this.regularDownload(artifactId, filename);
        }
    },

    regularDownload(artifactId, filename) {
        const a = document.createElement('a');
        a.href = `/api/v1/artifacts/${artifactId}/download`;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    },
};

// ─── Persistent Storage API ────────────────────────────────────────────────
// Request persistent storage on PWA install to prevent cache eviction.
(function initPersistentStorage() {
    if (!navigator.storage?.persist) return;

    // Request on PWA install event (beforeinstallprompt → userChoice)
    window.addEventListener('appinstalled', async () => {
        const granted = await navigator.storage.persist();
        if (!granted) return; // Browser declined — graceful degradation
    });
})();
