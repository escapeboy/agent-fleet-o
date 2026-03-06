<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    {{-- Header row --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('email.themes.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All Themes</a>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                {{ match($theme->status->value) {
                    'active' => 'bg-green-100 text-green-800',
                    'draft' => 'bg-gray-100 text-gray-700',
                    'archived' => 'bg-yellow-100 text-yellow-800',
                    default => 'bg-gray-100 text-gray-700',
                } }}">
                {{ $theme->status->label() }}
            </span>
            @if($isDefault)
                <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                    Default
                </span>
            @endif
        </div>
        <div class="flex gap-2">
            @if(!$isDefault && $theme->status->value === 'active')
                <button wire:click="setAsDefault"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                    Set as Default
                </button>
            @endif
            @if(!$editing)
                <button wire:click="startEdit"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Edit
                </button>
            @endif
        </div>
    </div>

    @if($editing)
        {{-- Edit Form --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">General</h3>
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editName" label="Theme Name" />
                    <x-form-select wire:model="editStatus" label="Status">
                        @foreach($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </x-form-select>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Brand Identity</h3>
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editLogoUrl" label="Logo URL" placeholder="https://..." type="url" />
                    <x-form-input wire:model="editLogoWidth" label="Logo Width (px)" type="number" min="50" max="400" />
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Colors</h3>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    @foreach([
                        ['editBackgroundColor', 'Background'],
                        ['editCanvasColor', 'Canvas (email body)'],
                        ['editPrimaryColor', 'Primary / Button'],
                        ['editTextColor', 'Body Text'],
                        ['editHeadingColor', 'Headings'],
                        ['editMutedColor', 'Muted / Footer'],
                        ['editDividerColor', 'Divider'],
                    ] as [$field, $label])
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">{{ $label }}</label>
                            <div class="flex items-center gap-2">
                                <input type="color" wire:model.live="{{ $field }}" class="h-9 w-12 cursor-pointer rounded border border-gray-300 p-0.5">
                                <input type="text" wire:model.live="{{ $field }}"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-primary-500 focus:ring-primary-500"
                                    placeholder="#000000" maxlength="7">
                            </div>
                            @error($field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Typography</h3>
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editFontName" label="Font Name" placeholder="Inter" />
                    <x-form-input wire:model="editFontUrl" label="Font Import URL" placeholder="https://fonts.bunny.net/css?family=inter:400,600" type="url" />
                    <x-form-input wire:model="editFontFamily" label="Font Family Stack" placeholder="Inter, Arial, sans-serif" />
                    <x-form-input wire:model="editLineHeight" label="Line Height" type="number" min="1" max="3" step="0.1" />
                    <x-form-input wire:model="editHeadingFontSize" label="Heading Font Size (px)" type="number" min="12" max="60" />
                    <x-form-input wire:model="editBodyFontSize" label="Body Font Size (px)" type="number" min="10" max="30" />
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Layout</h3>
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editEmailWidth" label="Email Width (px)" type="number" min="320" max="800" />
                    <x-form-input wire:model="editContentPadding" label="Content Padding (px)" type="number" min="8" max="80" />
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Footer</h3>
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editCompanyName" label="Company Name" placeholder="Acme Corp" />
                    <x-form-input wire:model="editCompanyAddress" label="Company Address" placeholder="123 Main St, City" />
                </div>
                <div class="mt-4">
                    <x-form-textarea wire:model="editFooterText" label="Footer Text" rows="3"
                        hint="Shown at the bottom of every email. Supports line breaks." />
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button wire:click="cancelEdit"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button wire:click="save"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Theme
                </button>
            </div>
        </div>
    @else
        {{-- View Mode --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Preview panel --}}
            <div class="lg:col-span-1">
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Preview</h3>

                    <div class="rounded-lg p-4" style="background-color: {{ $theme->background_color }}">
                        <div class="rounded-md p-4" style="background-color: {{ $theme->canvas_color }}; max-width: 100%;">
                            @if($theme->logo_url)
                                <img src="{{ $theme->logo_url }}" alt="Logo" style="max-width: {{ $theme->logo_width }}px; max-height: 60px; object-fit: contain; display: block; margin: 0 auto 12px;">
                            @endif
                            <h4 style="color: {{ $theme->heading_color }}; font-size: 18px; font-weight: 700; margin: 0 0 8px; font-family: {{ $theme->font_family }}">Your heading here</h4>
                            <p style="color: {{ $theme->text_color }}; font-size: {{ $theme->body_font_size }}px; line-height: {{ $theme->line_height }}; margin: 0 0 16px; font-family: {{ $theme->font_family }}">This is a sample paragraph to preview body text styling in your email theme.</p>
                            <a href="#" style="display: inline-block; background-color: {{ $theme->primary_color }}; color: #fff; padding: 10px 20px; border-radius: 6px; font-family: {{ $theme->font_family }}; font-size: 14px; text-decoration: none; font-weight: 600;">Call to Action</a>
                            <hr style="border: none; border-top: 1px solid {{ $theme->divider_color }}; margin: 16px 0;">
                            <p style="color: {{ $theme->muted_color }}; font-size: 12px; font-family: {{ $theme->font_family }}; margin: 0;">{{ $theme->footer_text ?? '© '.date('Y').' '.config('app.name').'. All rights reserved.' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Token list --}}
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Colors</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach([
                            [$theme->background_color, 'Background'],
                            [$theme->canvas_color, 'Canvas'],
                            [$theme->primary_color, 'Primary'],
                            [$theme->text_color, 'Body Text'],
                            [$theme->heading_color, 'Headings'],
                            [$theme->muted_color, 'Muted'],
                            [$theme->divider_color, 'Divider'],
                        ] as [$color, $label])
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-5 w-5 flex-shrink-0 rounded-full border border-gray-200" style="background-color: {{ $color }}"></span>
                                <span class="text-xs text-gray-500">{{ $label }}<br><span class="font-mono text-gray-700">{{ $color }}</span></span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Typography &amp; Layout</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Font</dt><dd class="font-medium text-gray-900">{{ $theme->font_name }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Font Family</dt><dd class="font-mono text-xs text-gray-700">{{ $theme->font_family }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Heading Size</dt><dd class="font-medium text-gray-900">{{ $theme->heading_font_size }}px</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Body Size</dt><dd class="font-medium text-gray-900">{{ $theme->body_font_size }}px</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Line Height</dt><dd class="font-medium text-gray-900">{{ $theme->line_height }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Email Width</dt><dd class="font-medium text-gray-900">{{ $theme->email_width }}px</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Content Padding</dt><dd class="font-medium text-gray-900">{{ $theme->content_padding }}px</dd></div>
                    </dl>
                </div>

                @if($theme->company_name || $theme->footer_text)
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Footer</h3>
                        <dl class="space-y-2 text-sm">
                            @if($theme->company_name)
                                <div class="flex justify-between"><dt class="text-gray-500">Company</dt><dd class="font-medium text-gray-900">{{ $theme->company_name }}</dd></div>
                            @endif
                            @if($theme->company_address)
                                <div class="flex justify-between"><dt class="text-gray-500">Address</dt><dd class="font-medium text-gray-900">{{ $theme->company_address }}</dd></div>
                            @endif
                            @if($theme->footer_text)
                                <div><dt class="mb-1 text-gray-500">Footer Text</dt><dd class="whitespace-pre-line text-gray-700">{{ $theme->footer_text }}</dd></div>
                            @endif
                        </dl>
                    </div>
                @endif

                {{-- Danger zone --}}
                <div class="rounded-xl border border-red-100 bg-red-50 p-6">
                    <h3 class="mb-2 text-sm font-semibold text-red-700">Danger Zone</h3>
                    <p class="mb-4 text-sm text-red-600">Deleting this theme is irreversible. Any emails using it will fall back to the platform default.</p>
                    <button wire:click="deleteTheme"
                        wire:confirm="Are you sure you want to delete this email theme?"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Delete Theme
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
