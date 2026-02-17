import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';

/**
 * Multi-tab terminal Alpine component.
 * Supports multiple concurrent output streams, each in its own tab.
 */
export default () => ({
    tabs: [],
    activeTabId: null,
    terminals: {},
    fitAddons: {},
    lastLengths: {},
    observer: null,

    init() {
        this.observer = new ResizeObserver(() => {
            if (this.activeTabId && this.fitAddons[this.activeTabId]) {
                try {
                    this.fitAddons[this.activeTabId].fit();
                } catch (e) { /* ignore fit errors */ }
            }
        });

        if (this.$refs.terminalHost) {
            this.observer.observe(this.$refs.terminalHost);
        }

        this.$cleanup = () => {
            this.observer?.disconnect();
            Object.values(this.terminals).forEach(t => t?.dispose());
        };
    },

    addTab(id, label) {
        if (this.tabs.find(t => t.id === id)) {
            this.switchTab(id);
            return;
        }

        this.tabs.push({ id, label: label || `Tab ${this.tabs.length + 1}` });

        this.$nextTick(() => {
            this.initTerminal(id);
            this.switchTab(id);
        });
    },

    removeTab(id) {
        if (this.terminals[id]) {
            this.terminals[id].dispose();
            delete this.terminals[id];
            delete this.fitAddons[id];
            delete this.lastLengths[id];
        }

        this.tabs = this.tabs.filter(t => t.id !== id);

        // Switch to next available tab
        if (this.activeTabId === id) {
            this.activeTabId = this.tabs.length > 0 ? this.tabs[this.tabs.length - 1].id : null;
            if (this.activeTabId) {
                this.$nextTick(() => this.fitAddons[this.activeTabId]?.fit());
            }
        }
    },

    switchTab(id) {
        this.activeTabId = id;
        this.$nextTick(() => {
            if (this.fitAddons[id]) {
                try {
                    this.fitAddons[id].fit();
                } catch (e) { /* ignore */ }
            }
        });
    },

    initTerminal(id) {
        const container = this.$refs['terminal-' + id];
        if (!container) return;

        const terminal = new Terminal({
            cursorBlink: false,
            disableStdin: true,
            fontSize: 13,
            fontFamily: 'JetBrains Mono, Menlo, Consolas, monospace',
            theme: {
                background: '#1e1e2e',
                foreground: '#cdd6f4',
                cursor: '#f5e0dc',
                selectionBackground: '#585b70',
            },
            convertEol: true,
            scrollback: 5000,
        });

        const fitAddon = new FitAddon();
        terminal.loadAddon(fitAddon);
        terminal.open(container);
        fitAddon.fit();

        this.terminals[id] = terminal;
        this.fitAddons[id] = fitAddon;
        this.lastLengths[id] = 0;
    },

    appendOutput(id, text) {
        if (!text || !this.terminals[id]) return;

        const lastLength = this.lastLengths[id] || 0;
        if (text.length > lastLength) {
            this.terminals[id].write(text.substring(lastLength));
            this.lastLengths[id] = text.length;
        }
    },

    clearTab(id) {
        if (this.terminals[id]) {
            this.terminals[id].clear();
            this.lastLengths[id] = 0;
        }
    },

    destroy() {
        this.$cleanup?.();
    },
});
