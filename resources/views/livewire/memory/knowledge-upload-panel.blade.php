<div class="rounded-lg border border-gray-200 bg-white p-4">
    <h3 class="text-sm font-semibold text-gray-900">Upload Knowledge Document</h3>
    <p class="mt-1 text-xs text-gray-500">Upload PDF, TXT, MD, or CSV files to create searchable memory chunks.</p>

    @if($success)
        <div class="mt-3 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ $success }}
        </div>
    @endif

    @if($error)
        <div class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ $error }}
        </div>
    @endif

    <form wire:submit="upload" class="mt-3 space-y-3">
        <div>
            <label class="block text-sm font-medium text-gray-700">File</label>
            <div class="mt-1 flex items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-6 py-4 transition hover:border-primary-400"
                 x-data="{ dragging: false }"
                 x-on:dragover.prevent="dragging = true"
                 x-on:dragleave="dragging = false"
                 x-on:drop.prevent="dragging = false"
                 :class="{ 'border-primary-500 bg-primary-50': dragging }">
                <div class="text-center">
                    <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <div class="mt-2 flex text-sm text-gray-600">
                        <label class="cursor-pointer rounded-md font-medium text-primary-600 hover:text-primary-500">
                            <span>Choose a file</span>
                            <input wire:model="file" type="file" class="sr-only" accept=".pdf,.txt,.md,.csv">
                        </label>
                        <p class="pl-1">or drag and drop</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">PDF, TXT, MD, CSV up to 10MB</p>
                </div>
            </div>
            @error('file') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror

            @if($file)
                <p class="mt-2 text-sm text-gray-600">
                    Selected: <span class="font-medium">{{ $file->getClientOriginalName() }}</span>
                    ({{ number_format($file->getSize() / 1024, 1) }} KB)
                </p>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="upload">Upload & Process</span>
                <span wire:loading wire:target="upload" class="flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Processing...
                </span>
            </button>

            <div wire:loading wire:target="file" class="text-sm text-gray-500">
                Uploading file...
            </div>
        </div>
    </form>
</div>
