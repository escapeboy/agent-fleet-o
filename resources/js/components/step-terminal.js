import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';

export default () => ({
    terminal: null,
    fitAddon: null,
    lastLength: 0,

    init() {
        this.terminal = new Terminal({
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

        this.fitAddon = new FitAddon();
        this.terminal.loadAddon(this.fitAddon);
        this.terminal.open(this.$refs.terminalContainer);
        this.fitAddon.fit();

        // Auto-resize when container changes
        const observer = new ResizeObserver(() => {
            try {
                this.fitAddon.fit();
            } catch (e) {
                // Ignore fit errors during transitions
            }
        });
        observer.observe(this.$refs.terminalContainer);

        // Cleanup on destroy
        this.$cleanup = () => {
            observer.disconnect();
            this.terminal?.dispose();
        };
    },

    appendOutput(text) {
        if (!text || !this.terminal) return;

        if (text.length > this.lastLength) {
            this.terminal.write(text.substring(this.lastLength));
            this.lastLength = text.length;
        }
    },

    destroy() {
        this.$cleanup?.();
    },
});
