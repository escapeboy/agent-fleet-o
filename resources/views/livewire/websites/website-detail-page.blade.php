<div>
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('websites.index') }}" class="text-sm text-gray-400 hover:text-gray-600">← Websites</a>
            <span class="text-gray-300">/</span>
            <span class="text-sm font-medium text-gray-700">{{ $website->name }}</span>
            @php
                $badgeColor = match($website->status) {
                    \App\Domain\Website\Enums\WebsiteStatus::Draft => 'gray',
                    \App\Domain\Website\Enums\WebsiteStatus::Published => 'green',
                    \App\Domain\Website\Enums\WebsiteStatus::Archived => 'yellow',
                };
            @endphp
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $badgeColor }}-100 text-{{ $badgeColor }}-800">
                {{ ucfirst($website->status->value) }}
            </span>
        </div>
        @if($website->status !== \App\Domain\Website\Enums\WebsiteStatus::Published)
            <button wire:click="publishWebsite"
                wire:confirm="Publish this website? All draft pages will be published and the site will go live."
                class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                Publish Website
            </button>
        @endif
    </div>

    {{-- Info card --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Slug</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $website->slug }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Custom Domain</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $website->custom_domain ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Created</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $website->created_at->diffForHumans() }}</dd>
            </div>
        </dl>
    </div>

    {{-- Pages section --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">Pages</h2>
            <button wire:click="$toggle('showAddPage')" class="text-sm text-primary-600 hover:text-primary-800">
                {{ $showAddPage ? '— Cancel' : '+ Add Page' }}
            </button>
        </div>

        @if($showAddPage)
            <div class="border-b border-gray-200 bg-gray-50 px-5 py-4">
                <form wire:submit="addPage" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-48">
                        <x-form-input
                            wire:model.live="pageTitle"
                            label="Title"
                            type="text"
                            placeholder="e.g. Home"
                            :error="$errors->first('pageTitle')"
                            compact
                        />
                    </div>
                    <div class="flex-1 min-w-40">
                        <x-form-input
                            wire:model="pageSlug"
                            label="Slug"
                            type="text"
                            placeholder="home"
                            :error="$errors->first('pageSlug')"
                            compact
                        />
                    </div>
                    <div class="w-36">
                        <x-form-select wire:model="pageType" label="Type" compact>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                            <option value="product">Product</option>
                            <option value="landing">Landing</option>
                        </x-form-select>
                    </div>
                    <button type="submit"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Add
                    </button>
                </form>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Title</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="hidden md:table-cell px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Published At</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($pages as $page)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-5 py-4">
                                <a href="{{ route('websites.pages.edit', [$website, $page]) }}"
                                    class="font-medium text-primary-600 hover:text-primary-800">
                                    {{ $page->title }}
                                </a>
                                <p class="mt-0.5 text-xs text-gray-400">{{ $page->slug }}</p>
                            </td>
                            <td class="px-5 py-4">
                                @php
                                    $typeColor = match($page->page_type) {
                                        \App\Domain\Website\Enums\WebsitePageType::Page => 'blue',
                                        \App\Domain\Website\Enums\WebsitePageType::Post => 'purple',
                                        \App\Domain\Website\Enums\WebsitePageType::Product => 'green',
                                        \App\Domain\Website\Enums\WebsitePageType::Landing => 'orange',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $typeColor }}-100 text-{{ $typeColor }}-800">
                                    {{ ucfirst($page->page_type->value) }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                @php
                                    $statusColor = match($page->status) {
                                        \App\Domain\Website\Enums\WebsitePageStatus::Draft => 'gray',
                                        \App\Domain\Website\Enums\WebsitePageStatus::Published => 'green',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800">
                                    {{ ucfirst($page->status->value) }}
                                </span>
                            </td>
                            <td class="hidden md:table-cell px-5 py-4 text-sm text-gray-500">
                                {{ $page->published_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($page->exported_html)
                                        <a href="{{ route('websites.pages.preview', [$website, $page]) }}" target="_blank"
                                           class="text-xs text-gray-500 hover:text-gray-700">
                                            Preview
                                        </a>
                                    @endif
                                    @if($page->status->value === 'draft')
                                        <button wire:click="publishPage('{{ $page->id }}')"
                                            class="text-xs text-green-600 hover:text-green-800">
                                            Publish
                                        </button>
                                    @endif
                                    <button wire:click="deletePage('{{ $page->id }}')"
                                        wire:confirm="Delete this page? This cannot be undone."
                                        class="text-xs text-red-500 hover:text-red-700">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">
                                No pages yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Danger zone --}}
    <div class="rounded-xl border border-red-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-red-700">Danger Zone</h3>
        <div class="flex items-center justify-between">
            <p class="text-sm text-gray-500">Permanently delete this website and all its pages.</p>
            <button wire:click="deleteWebsite"
                wire:confirm="Delete this website and all its pages? This cannot be undone."
                class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                Delete Website
            </button>
        </div>
    </div>
</div>
