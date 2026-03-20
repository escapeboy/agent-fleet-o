/**
 * WebMCP tool registration utilities for FleetQ.
 *
 * Provides safe register/unregister helpers that guard against
 * missing navigator.modelContext and duplicate registrations.
 * Designed for use inside Alpine.js components via x-init / $cleanup.
 *
 * @see https://developer.chrome.com/blog/webmcp-epp
 */

const REGISTERED_TOOLS = new Set();

/**
 * Register a WebMCP tool. No-op if navigator.modelContext is absent
 * or the tool is already registered.
 *
 * @param {Object} tool - WebMCP tool definition
 * @param {string} tool.name - Unique tool identifier
 * @param {string} tool.description - Human-readable description for the agent
 * @param {Object} tool.inputSchema - JSON Schema for tool parameters
 * @param {Function} tool.execute - Async handler receiving (params, client)
 * @param {Object} [tool.annotations] - Optional { readOnlyHint: boolean }
 */
export function registerTool(tool) {
    if (!navigator.modelContext) return;
    if (REGISTERED_TOOLS.has(tool.name)) return;

    try {
        navigator.modelContext.registerTool(tool);
        REGISTERED_TOOLS.add(tool.name);
    } catch (e) {
        console.warn(`[WebMCP] Failed to register "${tool.name}":`, e);
    }
}

/**
 * Unregister a previously registered WebMCP tool.
 * Safe to call even if the tool was never registered.
 *
 * @param {string} name - Tool name to unregister
 */
export function unregisterTool(name) {
    if (!navigator.modelContext) return;
    if (!REGISTERED_TOOLS.has(name)) return;

    try {
        navigator.modelContext.unregisterTool(name);
    } catch (_) {
        // InvalidStateError if already unregistered — safe to ignore
    }
    REGISTERED_TOOLS.delete(name);
}

/**
 * Unregister all tools registered through this module.
 * Useful for cleanup on page teardown.
 */
export function unregisterAll() {
    for (const name of REGISTERED_TOOLS) {
        unregisterTool(name);
    }
}

/**
 * Check if WebMCP is available in the current browser.
 *
 * @returns {boolean}
 */
export function isAvailable() {
    return !!navigator.modelContext;
}

// Re-register tools after Livewire SPA navigation (wire:navigate).
// On navigate, Alpine components are destroyed (triggering $cleanup)
// and re-created on the new page (triggering x-init). This listener
// cleans up the registry so fresh registrations don't hit duplicates.
document.addEventListener('livewire:navigating', () => {
    unregisterAll();
});

// Expose globally for inline Alpine components that can't use ES imports
window.FleetQWebMcp = { registerTool, unregisterTool, unregisterAll, isAvailable };
