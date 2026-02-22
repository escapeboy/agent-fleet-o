<x-layouts.public
    title="{{ $listing->name }} — {{ config('app.name') }} Marketplace"
    description="{{ Str::limit($listing->description, 160) }}"
>
    <x-slot:head>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "SoftwareApplication",
            "name": "{{ e($listing->name) }}",
            "url": "{{ route('marketplace.show', $listing) }}",
            "applicationCategory": "DeveloperApplication",
            "operatingSystem": "Web",
            "description": "{{ e(Str::limit($listing->description, 200)) }}",
            "softwareVersion": "{{ e($listing->version) }}",
            "offers": {
                "@@type": "Offer",
                "price": "0",
                "priceCurrency": "EUR"
            }
            @if($listing->review_count > 0)
            ,
            "aggregateRating": {
                "@@type": "AggregateRating",
                "ratingValue": "{{ $listing->avg_rating }}",
                "reviewCount": "{{ $listing->review_count }}",
                "bestRating": "5",
                "worstRating": "1"
            }
            @endif
        }
        </script>
    </x-slot:head>

    <x-landing.nav />

    <section class="bg-white py-12 sm:py-16">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            {{-- Breadcrumbs --}}
            <nav class="mb-6 text-sm text-gray-500" aria-label="Breadcrumb">
                <ol class="flex items-center gap-2">
                    <li><a href="{{ route('marketplace.index') }}" class="hover:text-gray-700">Marketplace</a></li>
                    @if($listing->category)
                        <li class="before:mr-2 before:content-['/']">
                            <a href="{{ route('marketplace.category', $listing->category) }}" class="hover:text-gray-700">{{ ucfirst($listing->category) }}</a>
                        </li>
                    @endif
                    <li class="before:mr-2 before:content-['/'] text-gray-900 font-medium">{{ $listing->name }}</li>
                </ol>
            </nav>

            {{-- Header --}}
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">{{ $listing->name }}</h1>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $listing->type === 'skill' ? 'bg-purple-100 text-purple-800' : ($listing->type === 'workflow' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') }}">
                            {{ ucfirst($listing->type) }}
                        </span>
                        <span class="text-sm text-gray-400">v{{ $listing->version }}</span>
                    </div>
                    <p class="mt-2 max-w-2xl text-gray-600">{{ $listing->description }}</p>
                    <p class="mt-1 text-sm text-gray-400">Published by {{ $listing->publisher?->name ?? $listing->team?->name ?? 'Community' }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <a href="{{ route('marketplace.index') }}"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                        Back
                    </a>
                    <a href="{{ route('register') }}"
                        class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-700">
                        Sign Up to Install
                    </a>
                </div>
            </div>

            {{-- Stats Cards --}}
            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ number_format($listing->install_count) }}</div>
                    <div class="text-sm text-gray-500">Installs</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center gap-1">
                        <span class="text-2xl font-bold text-gray-900">{{ $listing->review_count > 0 ? number_format($listing->avg_rating, 1) : '—' }}</span>
                        @if($listing->review_count > 0)
                            <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        @endif
                    </div>
                    <div class="text-sm text-gray-500">Rating ({{ $listing->review_count }} reviews)</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex flex-wrap gap-1">
                        @if($listing->category)
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $listing->category }}</span>
                        @endif
                        @foreach(($listing->tags ?? []) as $tag)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $tag }}</span>
                        @endforeach
                        @if(!$listing->category && empty($listing->tags))
                            <span class="text-sm text-gray-400">—</span>
                        @endif
                    </div>
                    <div class="mt-1 text-sm text-gray-500">Tags</div>
                </div>
            </div>

            {{-- Content Tabs --}}
            <div class="mt-10" x-data="{ tab: 'overview' }">
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8">
                        @foreach(['overview' => 'Overview', 'configuration' => 'Configuration', 'reviews' => 'Reviews'] as $tabKey => $tabLabel)
                            <button @click="tab = '{{ $tabKey }}'"
                                :class="tab === '{{ $tabKey }}' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                                class="whitespace-nowrap border-b-2 py-3 text-sm font-medium">
                                {{ $tabLabel }}
                                @if($tabKey === 'reviews')
                                    <span class="ml-1 text-xs text-gray-400">({{ $listing->review_count }})</span>
                                @endif
                            </button>
                        @endforeach
                    </nav>
                </div>

                {{-- Overview Tab --}}
                <div x-show="tab === 'overview'" class="mt-6">
                    <div class="rounded-xl border border-gray-200 bg-white p-6">
                        @if($listing->readme)
                            <div class="prose max-w-none text-sm text-gray-700">
                                {!! nl2br(e($listing->readme)) !!}
                            </div>
                        @else
                            <p class="text-sm text-gray-400">No README provided.</p>
                        @endif
                    </div>
                </div>

                {{-- Configuration Tab --}}
                <div x-show="tab === 'configuration'" x-cloak class="mt-6">
                    @php $snapshot = $listing->configuration_snapshot ?? []; @endphp

                    @if($listing->type === 'skill')
                        @if(!empty($snapshot['input_schema']['properties'] ?? []))
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <h3 class="mb-3 text-sm font-semibold text-gray-700">Input Schema</h3>
                                <div class="space-y-2">
                                    @foreach($snapshot['input_schema']['properties'] as $name => $def)
                                        <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                            <span class="font-mono text-sm">{{ $name }}</span>
                                            <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                    @elseif($listing->type === 'agent')
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <h3 class="mb-3 text-sm font-semibold text-gray-700">Agent Configuration</h3>
                            <dl class="space-y-3">
                                @if(!empty($snapshot['role']))
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Role</dt>
                                        <dd class="text-sm text-gray-700">{{ $snapshot['role'] }}</dd>
                                    </div>
                                @endif
                                @if(!empty($snapshot['goal']))
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Goal</dt>
                                        <dd class="text-sm text-gray-700">{{ $snapshot['goal'] }}</dd>
                                    </div>
                                @endif
                                @if(!empty($snapshot['provider']))
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Provider / Model</dt>
                                        <dd class="text-sm text-gray-700">{{ $snapshot['provider'] }} / {{ $snapshot['model'] ?? 'default' }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                    @elseif($listing->type === 'workflow')
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <h3 class="mb-3 text-sm font-semibold text-gray-700">Workflow Overview</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Nodes</dt>
                                    <dd class="text-sm text-gray-700">{{ $snapshot['node_count'] ?? count($snapshot['nodes'] ?? []) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Connections</dt>
                                    <dd class="text-sm text-gray-700">{{ count($snapshot['edges'] ?? []) }} edges</dd>
                                </div>
                            </dl>
                        </div>

                        @if(!empty($snapshot['nodes']))
                            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4">
                                <h3 class="mb-3 text-sm font-semibold text-gray-700">Nodes</h3>
                                <div class="space-y-2">
                                    @foreach($snapshot['nodes'] as $node)
                                        <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                                    {{ $node['type'] === 'start' ? 'bg-green-100 text-green-800' :
                                                       ($node['type'] === 'end' ? 'bg-red-100 text-red-800' :
                                                       ($node['type'] === 'conditional' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')) }}">
                                                    {{ ucfirst($node['type']) }}
                                                </span>
                                                <span class="text-sm text-gray-700">{{ $node['label'] }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    @if(empty($snapshot))
                        <p class="text-sm text-gray-400">No configuration details available.</p>
                    @endif
                </div>

                {{-- Reviews Tab --}}
                <div x-show="tab === 'reviews'" x-cloak class="mt-6 space-y-4">
                    @forelse($listing->reviews as $review)
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $review->user?->name ?? 'Anonymous' }}</span>
                                    <div class="flex items-center">
                                        @for($i = 1; $i <= 5; $i++)
                                            <span class="text-sm {{ $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300' }}">&#9733;</span>
                                        @endfor
                                    </div>
                                </div>
                                <span class="text-xs text-gray-400">{{ $review->created_at->diffForHumans() }}</span>
                            </div>
                            @if($review->comment)
                                <p class="mt-2 text-sm text-gray-600">{{ $review->comment }}</p>
                            @endif
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-gray-400">No reviews yet.</div>
                    @endforelse

                    <div class="mt-6 text-center">
                        <a href="{{ route('register') }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                            Sign up to leave a review &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
