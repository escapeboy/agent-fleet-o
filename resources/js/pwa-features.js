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

    // speechInput is defined inline in assistant-panel.blade.php to avoid
    // alpine:init timing race with async Vite script loading.
});

// ─── View Transitions API ──────────────────────────────────────────────────
// App-like page transitions for wire:navigate. Livewire 4.5+ may handle this
// natively — this is a safe enhancement for older 4.x versions.
(function initViewTransitions() {
    if (!document.startViewTransition) return;

    document.addEventListener('livewire:navigate', (event) => {
        if (!document.startViewTransition) return;
        if (typeof event.detail?.visit !== 'function') return;
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

// ─── WebAuthn / Passkeys ────────────────────────────────────────────────────
// Registration and authentication ceremony helpers.
// The server endpoints are provided by asbiin/laravel-webauthn.
// Feature-detected: hidden on browsers without PublicKeyCredential support.

function arrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let str = '';
    for (const byte of bytes) str += String.fromCharCode(byte);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64UrlToArrayBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const buffer = new ArrayBuffer(raw.length);
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return buffer;
}

// Recursively decode base64url strings in WebAuthn options returned by the server
function decodeCreationOptions(options) {
    options.challenge = base64UrlToArrayBuffer(options.challenge);
    options.user.id = base64UrlToArrayBuffer(options.user.id);
    if (options.excludeCredentials) {
        options.excludeCredentials = options.excludeCredentials.map((c) => ({
            ...c, id: base64UrlToArrayBuffer(c.id),
        }));
    }
    return options;
}

function decodeRequestOptions(options) {
    options.challenge = base64UrlToArrayBuffer(options.challenge);
    if (options.allowCredentials) {
        options.allowCredentials = options.allowCredentials.map((c) => ({
            ...c, id: base64UrlToArrayBuffer(c.id),
        }));
    }
    return options;
}

// Encode a PublicKeyCredential to JSON-serialisable form for the server
function encodeCredential(credential) {
    const response = credential.response;
    const encoded = {
        id: credential.id,
        rawId: arrayBufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {},
    };
    if (response.attestationObject) {
        encoded.response.attestationObject = arrayBufferToBase64Url(response.attestationObject);
    }
    if (response.clientDataJSON) {
        encoded.response.clientDataJSON = arrayBufferToBase64Url(response.clientDataJSON);
    }
    if (response.authenticatorData) {
        encoded.response.authenticatorData = arrayBufferToBase64Url(response.authenticatorData);
    }
    if (response.signature) {
        encoded.response.signature = arrayBufferToBase64Url(response.signature);
    }
    if (response.userHandle) {
        encoded.response.userHandle = arrayBufferToBase64Url(response.userHandle);
    }
    return encoded;
}

document.addEventListener('alpine:init', () => {
    // ─── Passkey Registration Component ────────────────────────────────────
    Alpine.data('passkeyRegister', () => ({
        supported: !!window.PublicKeyCredential,
        loading: false,
        error: null,
        keyName: '',

        async register() {
            if (!this.supported || this.loading) return;
            this.loading = true;
            this.error = null;

            try {
                const csrfToken = document.querySelector('meta[name=csrf-token]')?.content ?? '';

                // 1. Fetch creation options from server (POST to /webauthn/keys/options)
                const optRes = await fetch('/webauthn/keys/options', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                });
                if (!optRes.ok) throw new Error('Failed to get registration options');
                const json = await optRes.json();

                // 2. Create credential via browser API
                const options = decodeCreationOptions(json.publicKey ?? json);
                const credential = await navigator.credentials.create({ publicKey: options });
                if (!credential) throw new Error('Credential creation cancelled');

                // 3. Send attestation to server (POST to /webauthn/keys)
                const storeRes = await fetch('/webauthn/keys', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        ...encodeCredential(credential),
                        name: this.keyName || 'Security Key',
                    }),
                });

                if (!storeRes.ok) {
                    const err = await storeRes.json().catch(() => ({}));
                    throw new Error(err.message || 'Registration failed');
                }

                this.keyName = '';
                this.$dispatch('passkey-registered');
                window.location.reload(); // Refresh key list in Livewire component
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    this.error = 'Registration cancelled or timed out.';
                } else {
                    this.error = err.message || 'Passkey registration failed.';
                }
            } finally {
                this.loading = false;
            }
        },
    }));
});
