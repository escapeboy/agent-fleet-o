function isSameOriginApiRequest(args) {
    const input = args[0];
    const url = typeof input === 'string' ? input : (input instanceof Request ? input.url : String(input));
    if (url.startsWith('/api/')) {
        return true;
    }
    try {
        const parsed = new URL(url);
        return parsed.origin === window.location.origin && parsed.pathname.startsWith('/api/');
    } catch {
        return false;
    }
}

export function setupFleetQInvalidation() {
    const originalFetch = window.fetch;

    window.fetch = async function (...args) {
        const response = await originalFetch(...args);

        if (!isSameOriginApiRequest(args)) {
            return response;
        }

        const invalidateHeader = response.headers.get('X-FleetQ-Invalidate');
        if (invalidateHeader) {
            const tags = invalidateHeader.split(',').map(t => t.trim()).filter(Boolean);
            tags.forEach(tag => {
                window.dispatchEvent(new CustomEvent('fleetq:invalidate', { detail: { tag } }));
            });
        }

        return response;
    };
}
