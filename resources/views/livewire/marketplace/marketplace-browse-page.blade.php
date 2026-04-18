<div>
    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Featured Solution Packs --}}
    @if($featuredBundles->isNotEmpty() && $activeTab !== 'skills' && $activeTab !== 'connectors' && $activeTab !== 'channels')
        <div class="mb-6">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900">Solution Packs</h2>
                <button wire:click="setTab('bundles')" class="text-xs text-primary-600 hover:text-primary-800">Browse all</button>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($featuredBundles as $bundle)
                    <div class="flex flex-col rounded-xl border border-primary-100 bg-gradient-to-br from-primary-50 to-white p-4">
                        <div class="mb-1 flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 leading-tight">{{ $bundle->name }}</p>
                            <span class="shrink-0 rounded-full bg-primary-100 px-1.5 py-0.5 text-[10px] font-medium text-primary-700">Pack</span>
                        </div>
                        <p class="mt-1 flex-1 text-xs text-gray-500 line-clamp-2">{{ $bundle->description }}</p>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-[11px] text-gray-400">{{ number_format($bundle->install_count) }} installs</span>
                            <button wire:click="install('{{ $bundle->id }}')"
                                class="rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700 transition">
                                Install
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div class="mb-6">
        <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
            <button wire:click="setTab('all')"
                class="{{ $activeTab === 'all' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-900' }} px-4 py-2 rounded-lg text-sm font-medium transition-all">
                All
            </button>
            <button wire:click="setTab('skills')"
                class="{{ $activeTab === 'skills' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-900' }} px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Skills
            </button>
            <button wire:click="setTab('connectors')"
                class="{{ $activeTab === 'connectors' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-900' }} px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Connectors
            </button>
            <button wire:click="setTab('channels')"
                class="{{ $activeTab === 'channels' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-900' }} px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Channels
            </button>
            <button wire:click="setTab('bundles')"
                class="{{ $activeTab === 'bundles' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-900' }} px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Solution Packs
            </button>
        </div>
    </div>

    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_marketplace" tooldescription="Filter marketplace listings by type, category, pricing, and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search marketplace..." class="pl-10" toolparamdescription="Free-text search across marketplace listing names and descriptions">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="typeFilter" toolparamdescription="Filter by listing type: skill, agent, workflow, bundle">
            <option value="">All Types</option>
            <option value="skill">Skills</option>
            <option value="agent">Agents</option>
            <option value="workflow">Workflows</option>
            <option value="email_theme">Email Themes</option>
            <option value="email_template">Email Templates</option>
        </x-form-select>

        <x-form-select wire:model.live="categoryFilter" toolparamdescription="Filter by marketplace category">
            <option value="">All Categories</option>
            @foreach($categories as $cat)
                @php $label = config('marketplace-categories.'.$cat, ucfirst($cat)); @endphp
                <option value="{{ $cat }}">{{ $label }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="pricingFilter" toolparamdescription="Filter by pricing model: free, paid">
            <option value="">All Pricing</option>
            <option value="free">Free</option>
            <option value="paid">Paid</option>
        </x-form-select>

        <div class="flex flex-wrap items-center gap-2">
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
            <button wire:click="sortBy('run_count')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'run_count' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Most Used
            </button>
            <button wire:click="sortBy('community_quality_score')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'community_quality_score' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Best Quality
            </button>
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="verifiedQualityOnly" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
            Verified Quality
        </label>

        @auth
            @if($canPublish)
                <a href="{{ route('app.marketplace.publish') }}"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Publish
                </a>
            @else
                <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed" title="Plan limit reached">
                    Publish
                </span>
            @endif
        @endauth
    </form>

    {{-- Card Grid --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($listings as $listing)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:shadow-md">
                <div class="mb-3 flex items-start justify-between">
                    <div>
                        <a href="{{ route('app.marketplace.show', $listing) }}" class="text-lg font-semibold text-gray-900 hover:text-primary-600">
                            {{ $listing->name }}
                        </a>
                        @if($listing->is_official)
                            <span class="ml-2 inline-flex items-center gap-0.5 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                <i class="fa-solid fa-circle-check text-xs"></i>
                                Official
                            </span>
                        @endif
                        <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ match($listing->type) {
                                'skill' => 'bg-purple-100 text-purple-800',
                                'workflow' => 'bg-green-100 text-green-800',
                                'bundle' => 'bg-orange-100 text-orange-800',
                                'email_theme' => 'bg-pink-100 text-pink-800',
                                'email_template' => 'bg-rose-100 text-rose-800',
                                default => 'bg-blue-100 text-blue-800',
                            } }}">
                            {{ str_replace('_', ' ', ucfirst($listing->type)) }}
                        </span>
                        @if($listing->isPaid())
                            <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                {{ number_format($listing->price_per_run_credits, 0) }} cr/run
                            </span>
                        @else
                            <span class="ml-1 inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Free</span>
                        @endif
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-xs text-gray-400">v{{ $listing->version }}</span>
                        @php $riskLevel = $listing->risk_scan['level'] ?? null; @endphp
                        @if($riskLevel && $riskLevel !== 'none')
                            <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium
                                {{ match($riskLevel) {
                                    'low'      => 'bg-yellow-50 text-yellow-700',
                                    'medium'   => 'bg-orange-100 text-orange-700',
                                    'high'     => 'bg-red-100 text-red-700',
                                    'critical' => 'bg-red-200 text-red-900',
                                    default    => 'bg-gray-100 text-gray-500',
                                } }}"
                                title="{{ count($listing->risk_scan['findings'] ?? []) }} finding(s) — click listing for details">
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                {{ ucfirst($riskLevel) }} risk
                            </span>
                        @elseif($riskLevel === 'none')
                            <span class="inline-flex items-center gap-0.5 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700" title="No security concerns found">
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Safe
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Compatibility badge for skills with requirements --}}
                @php
                    $snapshot = $listing->configuration_snapshot ?? [];
                    $reqProviders = $snapshot['provider_requirements']['required_providers'] ?? [];
                    $compatible = empty($reqProviders) || !empty(array_intersect($reqProviders, $availableProviders));
                @endphp
                @if(!empty($reqProviders) && !$compatible)
                    <div class="mb-2 flex items-center gap-1 text-xs text-amber-600">
                        <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        Requires {{ implode(', ', $reqProviders) }}
                    </div>
                @elseif(!empty($reqProviders))
                    <div class="mb-2 flex items-center gap-1 text-xs text-green-600">
                        <i class="fa-solid fa-check text-sm"></i>
                        Compatible with your providers
                    </div>
                @endif

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
                        <span class="flex items-center gap-1" title="{{ number_format($listing->install_count) }} installs">
                            <i class="fa-solid fa-download text-base"></i>
                            {{ number_format($listing->install_count) }}
                        </span>
                        @if($listing->run_count > 0)
                            <span class="flex items-center gap-1" title="{{ number_format($listing->run_count) }} runs">
                                <i class="fa-solid fa-play text-base"></i>
                                {{ number_format($listing->run_count) }}
                            </span>
                        @endif
                        @if($listing->review_count > 0)
                            <span class="flex items-center gap-1">
                                <i class="fa-solid fa-star text-base text-yellow-400"></i>
                                {{ number_format($listing->avg_rating, 1) }} ({{ $listing->review_count }})
                            </span>
                        @endif
                        @if(($listing->community_quality_score ?? 0) > 0.5 && ($listing->install_success_rate ?? 0) > 0)
                            <span class="flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700" title="Community quality score: {{ number_format($listing->community_quality_score * 100, 0) }}%">
                                &#11088; {{ number_format($listing->install_success_rate * 100, 0) }}% effective
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
                    by {{ $listing->is_official ? 'FleetQ' : ($listing->team?->name ?? 'Unknown') }}
                </div>
            </div>
        @empty
            <div class="col-span-full py-16 text-center">
                <i class="fa-solid fa-inbox mx-auto text-4xl text-gray-300"></i>
                <p class="mt-4 text-sm text-gray-500">No listings found. Be the first to publish!</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $listings->links() }}
    </div>
</div>
