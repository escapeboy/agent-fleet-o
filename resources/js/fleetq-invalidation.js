export function setupFleetQInvalidation() {
    const originalFetch = window.fetch;

    window.fetch = async function (...args) {
        const response = await originalFetch(...args);

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
