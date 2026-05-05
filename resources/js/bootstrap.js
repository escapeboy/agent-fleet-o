import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Real-time broadcasting via Laravel Echo + Reverb. Powers /team-graph live
// activity firehose, experiment streaming, bridge daemon channel, etc.
//
// Reads runtime config from `window.FLEETQ_REVERB_CONFIG` (rendered by the
// Blade layout from server-side env). This way, env changes in production
// don't require rebuilding the frontend asset bundle. Falls back to
// import.meta.env for dev mode (Vite HMR).
const reverbCfg = window.FLEETQ_REVERB_CONFIG ?? {
    key: import.meta.env.VITE_REVERB_APP_KEY,
    host: import.meta.env.VITE_REVERB_HOST,
    port: parseInt(import.meta.env.VITE_REVERB_PORT, 10),
    scheme: import.meta.env.VITE_REVERB_SCHEME ?? 'https',
};

if (reverbCfg.key) {
    const Echo = (await import('laravel-echo')).default;
    const Pusher = (await import('pusher-js')).default;
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbCfg.key,
        wsHost: reverbCfg.host,
        wsPort: reverbCfg.port || 80,
        wssPort: reverbCfg.port || 443,
        forceTLS: reverbCfg.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
