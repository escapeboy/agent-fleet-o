<div>
    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-start justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-gray-900">{{ $listing->name }}</h2>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $listing->type === 'skill' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                    {{ ucfirst($listing->type) }}
                </span>
                <span class="text-sm text-gray-400">v{{ $listing->version }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $listing->description }}</p>
            <p class="mt-1 text-xs text-gray-400">Published by {{ $listing->team?->name ?? 'Unknown' }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('marketplace.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                Back
            </a>
            @auth
                @if($isInstalled)
                    <span class="rounded-lg border border-green-300 bg-green-50 px-4 py-1.5 text-sm font-medium text-green-700">
                        Installed
                    </span>
                @else
                    <button wire:click="install"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="install">Install</span>
                        <span wire:loading wire:target="install">Installing...</span>
                    </button>
                @endif
            @endauth
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-3 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ number_format($listing->install_count) }}</div>
            <div class="text-sm text-gray-500">Installs</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center gap-1">
                <span class="text-2xl font-bold text-gray-900">{{ $listing->review_count > 0 ? number_format($listing->avg_rating, 1) : '—' }}</span>
                @if($listing->review_count > 0)
                    <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                @endif
            </div>
            <div class="text-sm text-gray-500">Rating ({{ $listing->review_count }} reviews)</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap gap-1">
                @if($listing->category)
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $listing->category }}</span>
                @endif
                @foreach(($listing->tags ?? []) as $tag)
                    <span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">{{ $tag }}</span>
                @endforeach
            </div>
            <div class="mt-1 text-sm text-gray-500">Tags</div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            @foreach(['overview' => 'Overview', 'configuration' => 'Configuration', 'reviews' => 'Reviews'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'reviews')
                        <span class="ml-1 text-xs text-gray-400">({{ $listing->review_count }})</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'overview')
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            @if($listing->readme)
                <div class="prose max-w-none text-sm text-gray-700">
                    {!! nl2br(e($listing->readme)) !!}
                </div>
            @else
                <p class="text-sm text-gray-400">No README provided.</p>
            @endif
        </div>

    @elseif($activeTab === 'configuration')
        <div class="space-y-4">
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

                @if(!empty($snapshot['output_schema']['properties'] ?? []))
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Output Schema</h3>
                        <div class="space-y-2">
                            @foreach($snapshot['output_schema']['properties'] as $name => $def)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <span class="font-mono text-sm">{{ $name }}</span>
                                    <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                {{-- Agent config --}}
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
            @endif
        </div>

    @elseif($activeTab === 'reviews')
        <div class="space-y-6">
            {{-- Write Review --}}
            @auth
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Write a Review</h3>
                    <div class="mb-3 flex items-center gap-1">
                        @for($i = 1; $i <= 5; $i++)
                            <button wire:click="$set('reviewRating', {{ $i }})"
                                class="text-2xl {{ $i <= $reviewRating ? 'text-yellow-400' : 'text-gray-300' }} hover:text-yellow-400">
                                &#9733;
                            </button>
                        @endfor
                    </div>
                    <x-form-textarea wire:model="reviewComment" rows="3" placeholder="Share your experience (optional)" />
                    <button wire:click="submitReview"
                        class="mt-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Submit Review
                    </button>
                </div>
            @endauth

            {{-- Review List --}}
            @forelse($reviews as $review)
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
                <div class="py-8 text-center text-sm text-gray-400">No reviews yet. Be the first!</div>
            @endforelse
        </div>
    @endif
</div>
