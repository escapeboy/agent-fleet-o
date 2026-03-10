// FleetQ — Web Push subscription management
// Exposed globally as window.AgentFleetPush for Alpine.js integration

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

function getVapidKey() {
    return document.querySelector('meta[name="vapid-public-key"]')?.content || null;
}

async function isPushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window;
}

async function registerServiceWorker() {
    try {
        return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    } catch (e) {
        console.error('[AgentFleet] SW registration failed:', e);
        return null;
    }
}

async function checkSubscriptionStatus() {
    if (!(await isPushSupported())) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';

    const registration = await navigator.serviceWorker.getRegistration('/');
    if (!registration) return 'unsubscribed';

    const subscription = await registration.pushManager.getSubscription();
    return subscription ? 'subscribed' : 'unsubscribed';
}

async function subscribeToPush(wire) {
    const vapidKey = getVapidKey();
    if (!vapidKey) {
        console.error('[AgentFleet] VAPID public key not found in meta tag');
        return false;
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    try {
        const registration = await registerServiceWorker();
        if (!registration) return false;

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidKey),
        });

        const sub = subscription.toJSON();
        await wire.savePushSubscription({
            endpoint: sub.endpoint,
            keys: { p256dh: sub.keys.p256dh, auth: sub.keys.auth },
        });

        return true;
    } catch (e) {
        console.error('[AgentFleet] Push subscription failed:', e);
        return false;
    }
}

async function unsubscribeFromPush(wire) {
    const registration = await navigator.serviceWorker.getRegistration('/');
    if (!registration) return;

    const subscription = await registration.pushManager.getSubscription();
    if (subscription) {
        const endpoint = subscription.endpoint;
        await subscription.unsubscribe();
        await wire.deletePushSubscription(endpoint);
    }
}

// Register service worker on page load and handle updates
// Guard against double-registration: Livewire navigate() keeps JS context alive across page transitions
let swRegistered = false;

function initServiceWorker() {
    if (swRegistered || !('serviceWorker' in navigator)) return;
    swRegistered = true;

    navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then((registration) => {
            // Detect when a new SW version is waiting to activate
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                if (!newWorker) return;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New SW is waiting — notify the page so the update toast can prompt the user
                        window.dispatchEvent(new CustomEvent('fleetq:sw-update-ready'));
                    }
                });
            });
        })
        .catch(() => {});
}

// Reinit push UI status after Livewire navigate() replaces the page
// (the VAPID meta tag persists in <head> and SW registration persists — only UI state needs refresh)
document.addEventListener('livewire:navigated', () => {
    window.dispatchEvent(new CustomEvent('fleetq:push-status-refresh'));
});

window.addEventListener('load', initServiceWorker);

// Background Fetch completion — SW posts back when artifact download finishes
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'artifact-download-complete') {
            window.dispatchEvent(new CustomEvent('artifact-download-complete', {
                detail: { artifactId: event.data.artifactId },
            }));
        }
    });
}

window.AgentFleetPush = {
    checkSubscriptionStatus,
    subscribeToPush,
    unsubscribeFromPush,
};
