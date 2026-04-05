{{-- Builder data stored on window before Alpine boots.
     Embedding large HTML/JSON inside x-data attributes can cause SyntaxErrors;
     a dedicated <script> block keeps the data safely separate. --}}
<script>
window.__builderData = {
    json: @json($grapesJson),
    html: @json($exportedHtml),
    css:  @json($exportedCss),
    blocks: @json($blocks),
    scripts: @json($editorScripts),
    styles:  @json($editorStyles),
    previewUrl: @json(route('websites.page.preview', [$website, $page])),
};
</script>

<div
    wire:ignore
    x-data="websiteBuilder()"
    x-init="init()"
    class="flex flex-col"
    style="height: calc(100vh - 4rem);"
>
    {{-- Toolbar --}}
    <div class="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-2 shrink-0">
        <div class="flex items-center gap-3">
            <a href="{{ route('websites.show', $website) }}"
               class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fa-solid fa-arrow-left mr-1"></i>Back
            </a>
            <span class="text-gray-300">|</span>
            <span class="text-sm font-medium text-gray-900">{{ $page->title }}</span>
            <span class="text-xs text-gray-400">/{{ $page->slug }}</span>
        </div>

        <div class="flex items-center gap-2">
            {{-- Device toggles --}}
            <div class="flex rounded-md border border-gray-200 overflow-hidden">
                <button @click="setDevice('desktop')"
                    :class="device === 'desktop' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50'"
                    class="px-2.5 py-1.5 text-sm transition">
                    <i class="fa-solid fa-desktop"></i>
                </button>
                <button @click="setDevice('tablet')"
                    :class="device === 'tablet' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50'"
                    class="px-2.5 py-1.5 text-sm transition border-x border-gray-200">
                    <i class="fa-solid fa-tablet-screen-button"></i>
                </button>
                <button @click="setDevice('mobile')"
                    :class="device === 'mobile' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50'"
                    class="px-2.5 py-1.5 text-sm transition">
                    <i class="fa-solid fa-mobile-screen"></i>
                </button>
            </div>

            {{-- Undo / Redo --}}
            <button @click="undo()" class="rounded px-2 py-1.5 text-sm text-gray-500 hover:bg-gray-100" title="Undo">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
            <button @click="redo()" class="rounded px-2 py-1.5 text-sm text-gray-500 hover:bg-gray-100" title="Redo">
                <i class="fa-solid fa-rotate-right"></i>
            </button>

            {{-- Preview — shows the fully-styled page (canvas is unstyled for performance) --}}
            <button @click="preview()" class="rounded-lg border border-primary-300 bg-primary-50 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-100" title="Preview the styled page">
                <i class="fa-solid fa-eye mr-1"></i>Preview
            </button>

            {{-- Save --}}
            <button @click="saveContent()"
                :disabled="saving"
                class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span x-show="!saving"><i class="fa-solid fa-floppy-disk mr-1"></i>Save</span>
                <span x-show="saving"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...</span>
            </button>

            {{-- Saved indicator --}}
            <span x-show="showSaved" x-transition class="text-xs text-green-600">
                <i class="fa-solid fa-check mr-1"></i>Saved
            </span>
        </div>
    </div>

    {{-- Editor body --}}
    <div class="flex flex-1 overflow-hidden">
        {{-- Block panel --}}
        <div id="gjs-blocks-panel" class="w-56 shrink-0 overflow-y-auto border-r border-gray-200 bg-gray-50"></div>

        {{-- Canvas --}}
        <div id="gjs" class="flex-1"></div>

        {{-- Styles panel --}}
        <div class="w-64 shrink-0 overflow-y-auto border-l border-gray-200 bg-gray-50">
            <div id="gjs-styles-panel"></div>
            <div id="gjs-traits-panel" class="border-t border-gray-200"></div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css">

@foreach($editorScripts as $scriptUrl)
    <script src="{{ $scriptUrl }}"></script>
@endforeach

@foreach($editorStyles as $styleUrl)
    <link rel="stylesheet" href="{{ $styleUrl }}">
@endforeach

{{-- External file avoids inline-script injection from page-level MCP helpers (@mcp-b/global). --}}
<script src="{{ asset('js/website-builder.js') }}?v={{ filemtime(public_path('js/website-builder.js')) }}"></script>
@endpush
