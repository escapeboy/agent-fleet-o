{{--
    WebMCP — exposes FleetQ's public discovery documents as in-page tools so a
    browser agent visiting the site can learn how to connect without leaving
    the page.

    Every tool maps to a real, public, unauthenticated GET endpoint. No tool
    here mutates anything or requires credentials.

    Spec: https://webmachinelearning.github.io/webmcp/
--}}
<script>
(function () {
    if (!('modelContext' in navigator) || typeof navigator.modelContext.registerTool !== 'function') {
        return;
    }

    const controller = new AbortController();

    const fetchText = async (path) => {
        const response = await fetch(path, { headers: { 'Accept': 'text/plain, application/json' } });

        if (!response.ok) {
            return `Request to ${path} failed with HTTP ${response.status}.`;
        }

        return await response.text();
    };

    const tools = [
        {
            name: 'fleetq_connection_info',
            description: 'Get the FleetQ MCP discovery document: endpoint URLs, supported transports, and how to authenticate.',
            path: '/.well-known/fleetq',
        },
        {
            name: 'fleetq_platform_index',
            description: 'Get the compact FleetQ platform index (llms.txt): what the platform does and the main capability areas.',
            path: '/llms.txt',
        },
        {
            name: 'fleetq_api_catalog',
            description: 'Get the RFC 9727 API catalog listing the FleetQ REST API, its OpenAPI description, and the MCP endpoint.',
            path: '/.well-known/api-catalog',
        },
    ];

    for (const tool of tools) {
        navigator.modelContext.registerTool({
            name: tool.name,
            description: tool.description,
            inputSchema: { type: 'object', properties: {}, additionalProperties: false },
            execute: async () => ({
                content: [{ type: 'text', text: await fetchText(tool.path) }],
            }),
        }, { signal: controller.signal });
    }
})();
</script>
