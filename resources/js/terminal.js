import '@xterm/xterm/css/xterm.css';
import stepTerminal from './components/step-terminal.js';
import multiTerminal from './components/multi-terminal.js';

// Register Alpine components (Alpine is loaded globally by Livewire)
document.addEventListener('alpine:init', () => {
    Alpine.data('stepTerminal', stepTerminal);
    Alpine.data('multiTerminal', multiTerminal);
});
