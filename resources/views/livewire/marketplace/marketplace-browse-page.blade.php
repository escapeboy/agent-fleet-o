<div>
    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search marketplace..." class="pl-10">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            <option value="skill">Skills</option>
            <option value="agent">Agents</option>
        </x-form-select>

        <x-form-select wire:model.live="categoryFilter">
            <option value="">All Categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
            @endforeach
        </x-form-select>

        <div class="flex items-center gap-2">
            <button wire:click="sortBy('install_count')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'install_count' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Most Installed
            </button>
            <button wire:click="sortBy('avg_rating')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'avg_rating' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Top Rated
            </button>
            <button wire:click="sortBy('created_at')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'created_at' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Newest
            </button>
        </div>

        @auth
            <a href="{{ route('marketplace.publish') }}"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Publish
            </a>
        @endauth
    </div>

    {{-- Card Grid --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($listings as $listing)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:shadow-md">
                <div class="mb-3 flex items-start justify-between">
                    <div>
                        <a href="{{ route('marketplace.show', $listing) }}" class="text-lg font-semibold text-gray-900 hover:text-primary-600">
                            {{ $listing->name }}
                        </a>
                        <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $listing->type === 'skill' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                            {{ ucfirst($listing->type) }}
                        </span>
                    </div>
                    <span class="text-xs text-gray-400">v{{ $listing->version }}</span>
                </div>

                <p class="mb-4 flex-1 text-sm text-gray-500 line-clamp-3">{{ $listing->description }}</p>

                @if($listing->category || !empty($listing->tags))
                    <div class="mb-3 flex flex-wrap gap-1">
                        @if($listing->category)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $listing->category }}</span>
                        @endif
                        @foreach(($listing->tags ?? []) as $tag)
                            <span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                    <div class="flex items-center gap-4 text-sm text-gray-500">
                        <span class="flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            {{ number_format($listing->install_count) }}
                        </span>
                        @if($listing->review_count > 0)
                            <span class="flex items-center gap-1">
                                <svg class="h-4 w-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                {{ number_format($listing->avg_rating, 1) }} ({{ $listing->review_count }})
                            </span>
                        @endif
                    </div>

                    @auth
                        <button wire:click="install('{{ $listing->id }}')"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="install('{{ $listing->id }}')">Install</span>
                            <span wire:loading wire:target="install('{{ $listing->id }}')">Installing...</span>
                        </button>
                    @endauth
                </div>

                <div class="mt-2 text-xs text-gray-400">
                    by {{ $listing->team?->name ?? 'Unknown' }}
                </div>
            </div>
        @empty
            <div class="col-span-full py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                <p class="mt-4 text-sm text-gray-500">No listings found. Be the first to publish!</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $listings->links() }}
    </div>
</div>
