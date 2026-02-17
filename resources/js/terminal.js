import '@xterm/xterm/css/xterm.css';
import stepTerminal from './components/step-terminal.js';

// Register Alpine component (Alpine is loaded globally by Livewire)
document.addEventListener('alpine:init', () => {
    Alpine.data('stepTerminal', stepTerminal);
});
