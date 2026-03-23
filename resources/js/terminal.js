import '@xterm/xterm/css/xterm.css';
import stepTerminal from './components/step-terminal.js';
import multiTerminal from './components/multi-terminal.js';

// Upgrade a stub stepTerminal instance with real xterm, processing any
// buffered output that arrived before terminal.js loaded.
function initStep(component) {
    if (component.terminal) return;
    const pending = component._pending;
    Object.assign(component, stepTerminal());
    if (component.$refs?.terminalContainer) {
        component.init();
        if (pending) component.appendOutput(pending);
    }
}

// Upgrade a stub multiTerminal instance with real xterm, then initialize
// terminals for any tabs that were added before terminal.js loaded.
function initMulti(component) {
    if (component.observer) return;
    const existingTabs = [...component.tabs];
    Object.assign(component, multiTerminal());
    component.init();
    if (existingTabs.length) {
        component.$nextTick(() => {
            existingTabs.forEach(({ id }) => {
                if (!component.terminals[id]) component.initTerminal(id);
            });
            if (component.activeTabId) component.fitAddons[component.activeTabId]?.fit();
        });
    }
}

// Expose initializers so the layout stubs can call them.
window.__xtermInit = initStep;
window.__xtermMultiInit = initMulti;

// Process any components that were queued before this module loaded.
(window.__xtermQ || []).forEach(initStep);
(window.__xtermMQ || []).forEach(initMulti);
window.__xtermQ = [];
window.__xtermMQ = [];

// Register real Alpine.data for components created after terminal.js loads
// (e.g., on subsequent wire:navigate SPA navigations).
if (window.Alpine) {
    Alpine.data('stepTerminal', stepTerminal);
    Alpine.data('multiTerminal', multiTerminal);
} else {
    document.addEventListener('alpine:init', () => {
        Alpine.data('stepTerminal', stepTerminal);
        Alpine.data('multiTerminal', multiTerminal);
    });
}
