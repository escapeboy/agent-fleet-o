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

// Register service worker silently on page load (no subscription prompt)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}

window.AgentFleetPush = {
    checkSubscriptionStatus,
    subscribeToPush,
    unsubscribeFromPush,
};
