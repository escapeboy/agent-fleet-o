<div class="mx-auto max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('websites.index') }}" class="text-sm text-gray-400 hover:text-gray-600">← Websites</a>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="mb-6 text-lg font-semibold text-gray-900">New Website</h2>

        <form wire:submit="submit" class="space-y-4">
            <x-form-input
                wire:model.live="name"
                label="Name"
                type="text"
                placeholder="e.g. My Landing Page"
                :error="$errors->first('name')"
            />

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

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                    class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Website
                </button>
                <a href="{{ route('websites.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
