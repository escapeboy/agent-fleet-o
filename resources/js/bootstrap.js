import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Real-time broadcasting via Laravel Echo + Reverb. Powers /team-graph live
// activity firehose, experiment streaming, bridge daemon channel, etc.
// Activates only when VITE_REVERB_APP_KEY is set — pages that depend on
// window.Echo gracefully fall back to wire:poll when it is undefined.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    const Echo = (await import('laravel-echo')).default;
    const Pusher = (await import('pusher-js')).default;
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
