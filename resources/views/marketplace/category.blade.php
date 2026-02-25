<x-layouts.public
    title="{{ ucfirst($category) }} — {{ config('app.name') }} Marketplace"
    description="Browse {{ $category }} AI skills, agents, and workflows on the FleetQ marketplace."
>
    <x-landing.nav />

    <section class="bg-gradient-to-b from-gray-50 to-white py-12 sm:py-16">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            {{-- Breadcrumbs --}}
            <nav class="mb-6 text-sm text-gray-500" aria-label="Breadcrumb">
                <ol class="flex items-center gap-2">
                    <li><a href="{{ route('marketplace.index') }}" class="hover:text-gray-700">Marketplace</a></li>
                    <li class="before:mr-2 before:content-['/'] text-gray-900 font-medium">{{ ucfirst($category) }}</li>
                </ol>
            </nav>

            {{-- Header --}}
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-extrabold tracking-tight text-gray-900">
                        {{ ucfirst($category) }}
                    </h1>
                    <p class="mt-2 text-gray-600">
                        {{ $listings->total() }} {{ Str::plural('listing', $listings->total()) }} in this category
                    </p>
                </div>
                <a href="{{ route('marketplace.index') }}"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                    All Categories
                </a>
            </div>

            {{-- Search --}}
            <div class="mt-8">
                <form method="GET" action="{{ route('marketplace.category', $category) }}" class="flex items-center gap-4">
                    <div class="relative flex-1 max-w-md">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search in {{ $category }}..."
                            class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-4 text-sm focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Search
                    </button>
                </form>
            </div>

            {{-- Category Sidebar + Grid --}}
            <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-4">
                {{-- Category sidebar --}}
                <div class="lg:col-span-1">
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">Categories</h3>
                    <ul class="space-y-1">
                        @foreach($categories as $cat)
                            <li>
                                <a href="{{ route('marketplace.category', $cat->category) }}"
                                    class="flex items-center justify-between rounded-lg px-3 py-2 text-sm transition
                                        {{ $cat->category === $category ? 'bg-primary-50 font-medium text-primary-700' : 'text-gray-600 hover:bg-gray-50' }}">
                                    <span>{{ ucfirst($cat->category) }}</span>
                                    <span class="text-xs text-gray-400">{{ $cat->count }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Card Grid --}}
                <div class="lg:col-span-3">
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        @forelse($listings as $listing)
                            <a href="{{ route('marketplace.show', $listing) }}"
                                class="group flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-200 hover:shadow-md">
                                <div class="mb-3 flex items-start justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 group-hover:text-primary-600">
                                            {{ $listing->name }}
                                        </h3>
                                        <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $listing->type === 'skill' ? 'bg-purple-100 text-purple-800' : ($listing->type === 'workflow' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') }}">
                                            {{ ucfirst($listing->type) }}
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-400">v{{ $listing->version }}</span>
                                </div>

                                <p class="mb-4 flex-1 text-sm text-gray-500 line-clamp-3">{{ $listing->description }}</p>

                                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                                    <div class="flex items-center gap-4 text-sm text-gray-500">
                                        <span class="flex items-center gap-1">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            {{ number_format($listing->install_count) }}
                                        </span>
                                        @if($listing->review_count > 0)
                                            <span class="flex items-center gap-1">
                                                <svg class="h-4 w-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                                {{ number_format($listing->avg_rating, 1) }}
                                            </span>
                                        @endif
                                    </div>
                                    <span class="text-xs text-gray-400">by {{ $listing->team?->name ?? 'Community' }}</span>
                                </div>
                            </a>
                        @empty
                            <div class="col-span-full py-16 text-center">
                                <p class="text-gray-500">No listings in this category yet.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-6">
                        {{ $listings->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
