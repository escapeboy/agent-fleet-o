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
            <option value="workflow">Workflows</option>
            <option value="email_theme">Email Themes</option>
            <option value="email_template">Email Templates</option>
        </x-form-select>

        <x-form-select wire:model.live="categoryFilter">
            <option value="">All Categories</option>
            @foreach($categories as $cat)
                @php $label = config('marketplace-categories.'.$cat, ucfirst($cat)); @endphp
                <option value="{{ $cat }}">{{ $label }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="pricingFilter">
            <option value="">All Pricing</option>
            <option value="free">Free</option>
            <option value="paid">Paid</option>
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
            <button wire:click="sortBy('run_count')"
                class="rounded-lg border px-3 py-2 text-sm {{ $sortField === 'run_count' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-600' }}">
                Most Used
            </button>
        </div>

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
    </div>

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
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
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
                    <span class="text-xs text-gray-400">v{{ $listing->version }}</span>
                </div>

                {{-- Compatibility badge for skills with requirements --}}
                @php
                    $snapshot = $listing->configuration_snapshot ?? [];
                    $reqProviders = $snapshot['provider_requirements']['required_providers'] ?? [];
                    $compatible = empty($reqProviders) || !empty(array_intersect($reqProviders, $availableProviders));
                @endphp
                @if(!empty($reqProviders) && !$compatible)
                    <div class="mb-2 flex items-center gap-1 text-xs text-amber-600">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        Requires {{ implode(', ', $reqProviders) }}
                    </div>
                @elseif(!empty($reqProviders))
                    <div class="mb-2 flex items-center gap-1 text-xs text-green-600">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
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
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            {{ number_format($listing->install_count) }}
                        </span>
                        @if($listing->run_count > 0)
                            <span class="flex items-center gap-1" title="{{ number_format($listing->run_count) }} runs">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                {{ number_format($listing->run_count) }}
                            </span>
                        @endif
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
                    by {{ $listing->is_official ? 'FleetQ' : ($listing->team?->name ?? 'Unknown') }}
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
