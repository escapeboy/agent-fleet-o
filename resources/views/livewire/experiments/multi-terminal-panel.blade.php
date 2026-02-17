@push('styles')
    @once
        @vite('resources/js/terminal.js')
    @endonce
@endpush

<div wire:poll.3s="pollOutputs"
     x-data="multiTerminal()"
     x-init="init(); @foreach($tabs as $tab) addTab('{{ $tab['id'] }}', '{{ $tab['label'] }}'); @endforeach"
     @multi-terminal-output.window="appendOutput($event.detail.id, $event.detail.output)"
     @multi-terminal-new-tab.window="addTab($event.detail.id, $event.detail.label)"
     class="rounded-lg border border-gray-700 overflow-hidden">

    {{-- Tab bar --}}
    <div class="flex items-center bg-gray-800 border-b border-gray-700">
        <div class="flex items-center gap-0.5 overflow-x-auto px-2 py-1">
            {{-- Traffic lights --}}
            <div class="flex space-x-1.5 mr-3">
                <div class="w-2.5 h-2.5 rounded-full bg-red-500"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-yellow-500"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
            </div>

            <template x-for="tab in tabs" :key="tab.id">
                <button
                    @click="switchTab(tab.id)"
                    :class="activeTabId === tab.id
                        ? 'bg-gray-900 text-white border-b-2 border-blue-400'
                        : 'text-gray-400 hover:text-gray-200 hover:bg-gray-700'"
                    class="flex items-center gap-1.5 rounded-t px-3 py-1.5 text-xs font-medium transition"
                >
                    <span x-text="tab.label"></span>
                    <button @click.stop="removeTab(tab.id)" class="ml-1 text-gray-500 hover:text-red-400" x-show="tabs.length > 1">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </button>
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2 px-3">
            <button @click="activeTabId && clearTab(activeTabId)" class="text-xs text-gray-500 hover:text-gray-300" title="Clear">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
            <span class="text-xs text-gray-500">read-only</span>
        </div>
    </div>

    {{-- Terminal containers --}}
    <div wire:ignore x-ref="terminalHost" class="relative h-80">
        <template x-for="tab in tabs" :key="'term-' + tab.id">
            <div
                :x-ref="'terminal-' + tab.id"
                x-show="activeTabId === tab.id"
                class="absolute inset-0"
            ></div>
        </template>

        {{-- Empty state --}}
        <div x-show="tabs.length === 0" class="flex h-full items-center justify-center">
            <p class="text-sm text-gray-500">No active output streams</p>
        </div>
    </div>
</div>
