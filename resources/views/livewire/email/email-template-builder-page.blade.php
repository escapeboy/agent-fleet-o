<div
    x-data="emailTemplateBuilder(@js($designJson), @js($template->html_cache))"
    x-init="init()"
>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Top bar --}}
    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('email.templates.index') }}" class="text-sm text-gray-400 hover:text-gray-600">
                ← Templates
            </a>
            <span class="text-gray-300">/</span>
            <span class="text-sm font-medium text-gray-700">{{ $template->name }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if($template->html_cache)
                <a href="{{ route('email.templates.preview', $template) }}" target="_blank"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                    Preview
                </a>
            @endif
            <button @click="save()" class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Save
            </button>
        </div>
    </div>

    <div class="flex gap-6">
        {{-- GrapesJS canvas --}}
        <div class="flex-1">
            <div wire:ignore id="gjs" style="min-height: 600px; border: 1px solid #e5e7eb; border-radius: 0.5rem; overflow: hidden;"></div>
        </div>

        {{-- Settings panel --}}
        <div class="w-72 shrink-0">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="mb-4 text-sm font-semibold text-gray-900">Template Settings</h3>

                <div class="space-y-4">
                    <x-form-input wire:model="name" label="Name" />
                    <x-form-input wire:model="subject" label="Subject line" placeholder="e.g. Welcome to FleetQ!" />
                    <x-form-input wire:model="previewText" label="Preview text" placeholder="Short text shown in email clients..." />

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                        <select wire:model="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($statuses as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">Visibility</label>
                        <select wire:model="visibility" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($visibilities as $v)
                                <option value="{{ $v->value }}">{{ $v->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($themes->count())
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Email Theme</label>
                            <select wire:model="emailThemeId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">— None —</option>
                                @foreach($themes as $theme)
                                    <option value="{{ $theme->id }}">{{ $theme->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <button wire:click="saveSettings" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Save Settings
                    </button>

                    <div class="border-t border-gray-200 pt-4">
                        <button
                            wire:click="deleteTemplate"
                            wire:confirm="Delete this template? This cannot be undone."
                            class="w-full rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                        >
                            Delete Template
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css" />
<script src="https://unpkg.com/grapesjs-preset-newsletter@1.0.2/dist/index.js"></script>

<script>
function emailTemplateBuilder(initialDesign, htmlCache) {
    let editor;
    return {
        init() {
            editor = grapesjs.init({
                container: '#gjs',
                plugins: ['grapesjs-preset-newsletter'],
                pluginsOpts: {
                    'grapesjs-preset-newsletter': {
                        modalLabelImport: 'Paste MJML or HTML here',
                        modalBtnImport: 'Import',
                    },
                },
                storageManager: false,
                height: '600px',
                width: 'auto',
            });

            if (initialDesign && initialDesign.pages) {
                // GrapesJS native project data (saved via the editor)
                editor.loadProjectData(initialDesign);
            } else if (htmlCache) {
                // Fallback: AI-generated or MJML-compiled HTML — load into canvas
                editor.setComponents(htmlCache);
            }
        },

        save() {
            const html = editor.runCommand('gjs-get-inlined-html');
            const json = editor.getProjectData();
            this.$wire.save(html, JSON.stringify(json));
        },
    };
}
</script>
@endpush
