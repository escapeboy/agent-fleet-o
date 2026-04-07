<div class="mx-auto max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('websites.index') }}" class="text-sm text-gray-400 hover:text-gray-600">← Websites</a>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="mb-6 text-lg font-semibold text-gray-900">New Website</h2>

        {{-- Mode toggle --}}
        <div class="mb-6 flex gap-2 rounded-lg border border-gray-200 bg-gray-50 p-1">
            <button type="button" wire:click="$set('mode', 'blank')"
                class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition {{ $mode === 'blank' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                Blank Website
            </button>
            <button type="button" wire:click="$set('mode', 'generate')"
                class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition {{ $mode === 'generate' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                ✨ Generate with AI
            </button>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <x-form-input
                wire:model.live="name"
                label="Name"
                type="text"
                placeholder="e.g. My Landing Page"
                :error="$errors->first('name')"
            />

            @if($mode === 'blank')
                <x-form-input
                    wire:model="slug"
                    label="Slug"
                    type="text"
                    placeholder="my-landing-page"
                    hint="Auto-generated from name. Used in the website URL."
                    :error="$errors->first('slug')"
                />

                <x-form-input
                    wire:model="customDomain"
                    label="Custom Domain"
                    type="text"
                    placeholder="example.com"
                    hint="Optional. Leave blank to use the default subdomain."
                />
            @else
                <x-form-textarea
                    wire:model="prompt"
                    label="Describe your website"
                    placeholder="e.g. A SaaS landing page for a project management tool targeting remote teams. Include a hero section, features, pricing, and a contact form."
                    rows="5"
                    hint="The AI will generate a complete multi-page website with navigation and contact forms."
                    :error="$errors->first('prompt')"
                />
            @endif

            <div class="flex items-center gap-3 pt-2">
                @if($mode === 'generate')
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">✨ Generate Website</span>
                        <span wire:loading wire:target="submit">Generating… this may take a moment</span>
                    </button>
                @else
                    <button type="submit"
                        class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Create Website
                    </button>
                @endif
                <a href="{{ route('websites.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
