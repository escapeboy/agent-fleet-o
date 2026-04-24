@props(['count' => 0])

@if($count > 0)
    <div class="flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2">
        <i class="fa-solid fa-layer-group text-indigo-600"></i>
        <span class="text-sm text-indigo-700">
            <span class="font-semibold">{{ $count }}</span> selected
        </span>
        <div class="ml-auto flex items-center gap-2">
            <button wire:click="askAssistant"
                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-700">
                <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
                Ask AI about these
            </button>
            <button wire:click="clearSelection"
                    class="inline-flex items-center gap-1.5 rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100">
                Clear
            </button>
        </div>
    </div>
@endif
