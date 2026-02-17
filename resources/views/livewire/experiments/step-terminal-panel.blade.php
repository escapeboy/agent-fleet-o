@push('styles')
    @once
        @vite('resources/js/terminal.js')
    @endonce
@endpush

<div wire:poll.2s="pollOutput"
     x-data="stepTerminal()"
     x-init="init()"
     @terminal-output-{{ $stepId }}.window="appendOutput($event.detail.output)"
     class="h-80 rounded-lg overflow-hidden border border-gray-700">
    <div class="flex items-center justify-between px-3 py-1.5 bg-gray-800 border-b border-gray-700">
        <div class="flex items-center gap-2">
            <div class="flex space-x-1.5">
                <div class="w-2.5 h-2.5 rounded-full bg-red-500"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-yellow-500"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
            </div>
            <span class="text-xs text-gray-400">Live Output</span>
        </div>
        <span class="text-xs text-gray-500">read-only</span>
    </div>
    <div wire:ignore x-ref="terminalContainer" class="h-[calc(100%-2rem)]"></div>
</div>
