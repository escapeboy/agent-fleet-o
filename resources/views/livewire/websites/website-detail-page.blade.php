<div @if($website->isGenerating()) wire:poll.5000ms @endif>
    {{-- Header bar --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium
                bg-{{ $website->status->color() }}-100 text-{{ $website->status->color() }}-800">
                @if($website->isGenerating())
                    <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                @endif
                {{ $website->status->label() }}
            </span>
            <span class="text-sm text-gray-400">{{ $website->slug }}</span>
            @if($website->custom_domain)
                <span class="text-sm text-gray-400">·</span>
                <span class="text-sm text-gray-400">{{ $website->custom_domain }}</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if($website->status->value === 'published')
                <button wire:click="unpublish"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Unpublish
                </button>
            @else
                <button wire:click="publish"
                    class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                    Publish
                </button>
            @endif

            <a href="{{ route('websites.export', $website) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fa-solid fa-file-zipper mr-1"></i>Export ZIP
            </a>

            <button wire:click="startEditWebsite"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fa-solid fa-pencil mr-1"></i>Edit
            </button>

            <button wire:click="deleteWebsite"
                wire:confirm="Delete this website and all its pages? This cannot be undone."
                class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">
                <i class="fa-solid fa-trash mr-1"></i>Delete
            </button>
        </div>
    </div>

    {{-- Edit website modal --}}
    @if($editingWebsite)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">Edit Website</h3>
                <form wire:submit="saveWebsite" class="space-y-4">
                    <x-form-input wire:model="editName" label="Name" required />
                    <x-form-input wire:model="editSlug" label="Slug" hint="Only lowercase letters, numbers, and hyphens." />
                    <x-form-input wire:model="editCustomDomain" label="Custom Domain" placeholder="www.example.com" />
                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Save
                        </button>
                        <button type="button" wire:click="$set('editingWebsite', false)"
                            class="text-sm text-gray-500 hover:text-gray-700">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Assets section --}}
    <div class="mb-6">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900">Assets</h2>
        </div>

        <form wire:submit="uploadAsset" class="mb-4 flex items-center gap-3">
            <input type="file" wire:model="newAsset" accept="image/*"
                class="block text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100">
            <button type="submit"
                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                <i class="fa-solid fa-upload mr-1"></i>Upload
            </button>
        </form>
        @error('newAsset') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

        @if($website->assets->isEmpty())
            <p class="text-sm text-gray-400">No assets yet. Upload images to use in your pages.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach($website->assets as $asset)
                    <div class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <img src="{{ $asset->url }}" alt="{{ $asset->filename }}"
                            class="h-24 w-full object-cover">
                        <div class="px-2 py-1.5">
                            <p class="truncate text-xs text-gray-500" title="{{ $asset->filename }}">{{ $asset->filename }}</p>
                            <p class="text-xs text-gray-400">{{ number_format($asset->size_bytes / 1024, 0) }} KB</p>
                        </div>
                        <button wire:click="deleteAsset('{{ $asset->id }}')"
                            wire:confirm="Delete this asset?"
                            class="absolute right-1 top-1 hidden rounded bg-red-500 px-1.5 py-0.5 text-xs text-white group-hover:block">
                            <i class="fa-solid fa-times"></i>
                        </button>
                        <button type="button"
                            onclick="navigator.clipboard.writeText('{{ $asset->url }}')"
                            class="absolute left-1 top-1 hidden rounded bg-gray-700/70 px-1.5 py-0.5 text-xs text-white group-hover:block"
                            title="Copy URL">
                            <i class="fa-solid fa-copy"></i>
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Visual builder warning --}}
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <i class="fa-solid fa-triangle-exclamation mr-1.5"></i>
        The visual page builder is currently unavailable due to a known performance issue that freezes the browser. Pages can still be managed and published below.
    </div>

    {{-- Pages section --}}
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">Pages</h2>
        <button wire:click="$set('addingPage', true)"
            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
            <i class="fa-solid fa-plus mr-1"></i>Add Page
        </button>
    </div>

    {{-- Add page form --}}
    @if($addingPage)
        <div class="mb-4 rounded-xl border border-primary-200 bg-primary-50 p-4">
            <form wire:submit="addPage" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-40">
                    <x-form-input wire:model.live="newPageTitle" label="Title" placeholder="About Us" compact required />
                </div>
                <div class="flex-1 min-w-40">
                    <x-form-input wire:model="newPageSlug" label="Slug" placeholder="about-us" compact required />
                </div>
                <div class="min-w-32">
                    <x-form-select wire:model="newPageType" label="Type" compact>
                        <option value="page">Page</option>
                        <option value="post">Post</option>
                        <option value="product">Product</option>
                        <option value="landing">Landing</option>
                    </x-form-select>
                </div>
                <div class="flex items-center gap-2 pb-0.5">
                    <button type="submit"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Add
                    </button>
                    <button type="button" wire:click="$set('addingPage', false)"
                        class="text-sm text-gray-500 hover:text-gray-700">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Pages list --}}
    @if($website->pages->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-12">
            <i class="fa-solid fa-file-lines text-3xl text-gray-300 mb-3"></i>
            <p class="text-sm text-gray-500">No pages yet. Add your first page to start building.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Page</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Content</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($website->pages->sortBy('sort_order') as $page)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div>
                                    <span class="font-medium text-gray-900">{{ $page->title }}</span>
                                    <p class="text-xs text-gray-400">/{{ $page->slug }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 capitalize">{{ $page->page_type->value }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $page->status->value === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $page->status->value === 'published' ? 'Published' : 'Draft' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                @if($page->exported_html)
                                    <span class="text-green-600"><i class="fa-solid fa-check mr-1"></i>Has content</span>
                                @else
                                    <span class="text-gray-300">Empty</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('websites.page.preview', [$website, $page]) }}"
                                        target="_blank"
                                        class="text-sm text-blue-500 hover:text-blue-700" title="Preview">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    @if($page->status->value === 'published')
                                        <button wire:click="unpublishPage('{{ $page->id }}')"
                                            class="text-sm text-amber-600 hover:text-amber-800" title="Unpublish">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </button>
                                    @else
                                        <button wire:click="publishPage('{{ $page->id }}')"
                                            class="text-sm text-green-600 hover:text-green-800" title="Publish">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    @endif
                                    <button wire:click="deletePage('{{ $page->id }}')"
                                        wire:confirm="Delete this page?"
                                        class="text-sm text-red-400 hover:text-red-600">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
