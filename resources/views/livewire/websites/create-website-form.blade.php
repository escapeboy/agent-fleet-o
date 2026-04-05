<div class="max-w-2xl">
    {{-- Mode toggle --}}
    <div class="mb-6 flex rounded-lg border border-gray-200 bg-white p-1 w-fit">
        <button wire:click="$set('mode', 'manual')"
            class="rounded-md px-4 py-2 text-sm font-medium transition
                {{ $mode === 'manual' ? 'bg-primary-600 text-white' : 'text-gray-600 hover:text-gray-900' }}">
            <i class="fa-solid fa-pencil mr-1.5"></i>Manual
        </button>
        <button wire:click="$set('mode', 'ai')"
            class="rounded-md px-4 py-2 text-sm font-medium transition
                {{ $mode === 'ai' ? 'bg-primary-600 text-white' : 'text-gray-600 hover:text-gray-900' }}">
            <i class="fa-solid fa-wand-magic-sparkles mr-1.5"></i>Generate with AI
        </button>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        @if($mode === 'manual')
            <form wire:submit="create">
                <div class="space-y-4">
                    <x-form-input
                        wire:model.live="name"
                        label="Website Name"
                        placeholder="My Awesome Website"
                        required
                    />

                    <x-form-input
                        wire:model="slug"
                        label="Slug"
                        placeholder="my-awesome-website"
                        hint="Used in the public URL. Only lowercase letters, numbers, and hyphens."
                    />
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit"
                        class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove>Create Website</span>
                        <span wire:loading>Creating...</span>
                    </button>
                    <a href="{{ route('websites.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        @else
            <form wire:submit="generate">
                <div class="space-y-4">
                    <x-form-textarea
                        wire:model="prompt"
                        label="Describe your website"
                        placeholder="A modern SaaS landing page for a project management tool. Include a hero section, features, pricing (3 tiers), and a contact form."
                        hint="Be specific about the type of site, industry, and key sections you need."
                        rows="5"
                        required
                    />

                </div>

                <div class="mt-6 space-y-3">
                    <div class="flex items-center gap-3">
                        <button type="submit"
                            class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            wire:loading.attr="disabled"
                            @if($generating) disabled @endif>
                            <span wire:loading.remove wire:target="generate">
                                <i class="fa-solid fa-wand-magic-sparkles mr-1.5"></i>Generate
                            </span>
                            <span wire:loading wire:target="generate">
                                <i class="fa-solid fa-spinner fa-spin mr-1.5"></i>Starting…
                            </span>
                        </button>
                        <a href="{{ route('websites.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    </div>
                    <p class="text-xs text-gray-400">
                        <i class="fa-solid fa-circle-info mr-1"></i>
                        An AI crew will build your website in the background. You'll be redirected to track progress.
                    </p>
                </div>
            </form>
        @endif
    </div>
</div>
