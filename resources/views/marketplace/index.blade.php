<x-layouts.public
    title="Marketplace — {{ config('app.name') }}"
    description="Browse and install AI skills, agents, and workflows from the Agent Fleet marketplace."
    keywords="AI marketplace, AI agents, AI skills, AI workflows, agent templates"
>
    <x-slot:head>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "CollectionPage",
            "name": "Agent Fleet Marketplace",
            "url": "{{ route('marketplace.index') }}",
            "description": "Browse and install AI skills, agents, and workflows from the Agent Fleet marketplace."
        }
        </script>
    </x-slot:head>

    <x-landing.nav />

    <section class="bg-gradient-to-b from-gray-50 to-white py-12 sm:py-16">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            {{-- Header --}}
            <div class="text-center">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">
                    Marketplace
                </h1>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                    Browse community-built AI skills, agents, and workflows. Install with one click.
                </p>
            </div>

            {{-- Search & Filters --}}
            <div class="mt-10" x-data="{ search: '{{ request('search', '') }}', type: '{{ request('type', '') }}', sort: '{{ request('sort', '-install_count') }}' }">
                <form method="GET" action="{{ route('marketplace.index') }}" class="flex flex-wrap items-center gap-4">
                    {{-- Search --}}
                    <div class="relative flex-1 min-w-[200px]">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search marketplace..."
                            class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-4 text-sm focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    {{-- Type filter --}}
                    <select name="type" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">All Types</option>
                        <option value="skill" {{ request('type') === 'skill' ? 'selected' : '' }}>Skills</option>
                        <option value="agent" {{ request('type') === 'agent' ? 'selected' : '' }}>Agents</option>
                        <option value="workflow" {{ request('type') === 'workflow' ? 'selected' : '' }}>Workflows</option>
                    </select>

                    {{-- Category filter --}}
                    @if($categories->isNotEmpty())
                        <select name="category" onchange="this.form.submit()"
                            class="rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">All Categories</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->category }}" {{ request('category') === $cat->category ? 'selected' : '' }}>
                                    {{ ucfirst($cat->category) }} ({{ $cat->count }})
                                </option>
                            @endforeach
                        </select>
                    @endif

                    {{-- Sort buttons --}}
                    <div class="flex items-center gap-2">
                        @foreach(['-install_count' => 'Most Installed', '-avg_rating' => 'Top Rated', '-created_at' => 'Newest'] as $sortVal => $sortLabel)
                            <a href="{{ route('marketplace.index', array_merge(request()->query(), ['sort' => $sortVal])) }}"
                                class="rounded-lg border px-3 py-2 text-sm {{ request('sort', '-install_count') === $sortVal ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}">
                                {{ $sortLabel }}
                            </a>
                        @endforeach
                    </div>

                    <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Search
                    </button>
                </form>
            </div>

            {{-- Card Grid --}}
            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
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

                        @if($listing->category || !empty($listing->tags))
                            <div class="mb-3 flex flex-wrap gap-1">
                                @if($listing->category)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $listing->category }}</span>
                                @endif
                                @foreach(array_slice($listing->tags ?? [], 0, 3) as $tag)
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
                                        {{ number_format($listing->avg_rating, 1) }}
                                    </span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400">by {{ $listing->team?->name ?? 'Community' }}</span>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-16 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        <p class="mt-4 text-gray-500">No listings found.</p>
                        <p class="mt-1 text-sm text-gray-400">Check back soon or try a different search.</p>
                    </div>
                @endforelse
            </div>

            {{-- Pagination --}}
            <div class="mt-8">
                {{ $listings->links() }}
            </div>

            {{-- CTA --}}
            <div class="mt-16 text-center">
                <p class="text-gray-600">Have a skill, agent, or workflow to share?</p>
                <a href="{{ route('register') }}" class="mt-3 inline-block rounded-lg bg-primary-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-primary-700">
                    Sign Up to Publish
                </a>
            </div>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
