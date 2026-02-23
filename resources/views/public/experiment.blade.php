<x-layouts.public
    :title="$experiment->title . ' — ' . config('app.name')"
    :description="$experiment->thesis ?? 'Shared experiment from ' . config('app.name')"
>
    {{-- Simple header --}}
    <div class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
            <div class="flex items-center gap-2">
                <span class="text-base font-bold text-gray-900">{{ config('app.name') }}</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Shared Experiment</span>
            </div>
            <a href="{{ route('login') }}" class="text-sm text-primary-600 hover:text-primary-700">Sign in →</a>
        </div>
    </div>

    <div class="mx-auto max-w-4xl px-6 py-8">
        {{-- Title & Status --}}
        <div class="mb-6">
            <div class="mb-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">{{ $experiment->title }}</h1>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $experiment->status->value === 'completed' ? 'bg-green-100 text-green-800' :
                       ($experiment->status->value === 'killed' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800') }}">
                    {{ ucfirst(str_replace('_', ' ', $experiment->status->value)) }}
                </span>
            </div>
            @if($experiment->thesis)
                <p class="text-gray-600">{{ $experiment->thesis }}</p>
            @endif
            <p class="mt-2 text-sm text-gray-400">
                @if($experiment->started_at)
                    Started {{ $experiment->started_at->diffForHumans() }}
                @endif
                @if($experiment->completed_at)
                    · Completed {{ $experiment->completed_at->diffForHumans() }}
                @endif
            </p>
        </div>

        {{-- Stats --}}
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-{{ $config['show_costs'] ? '4' : '3' }}">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $stages->count() }}</p>
                <p class="text-sm text-gray-500">Stages</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $experiment->current_iteration }}</p>
                <p class="text-sm text-gray-500">Iterations</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $experiment->outbound_count }}</p>
                <p class="text-sm text-gray-500">Messages Sent</p>
            </div>
            @if($config['show_costs'])
                <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ number_format($experiment->budget_spent_credits) }}</p>
                    <p class="text-sm text-gray-500">Credits Used</p>
                </div>
            @endif
        </div>

        {{-- Stages --}}
        @if($config['show_stages'] && $stages->isNotEmpty())
            <div class="mb-8">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">Pipeline Stages</h2>
                <div class="space-y-3">
                    @foreach($stages as $stage)
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold
                                        {{ $stage->status === 'completed' ? 'bg-green-100 text-green-700' :
                                           ($stage->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                                        {{ $loop->iteration }}
                                    </span>
                                    <span class="font-medium text-gray-900">{{ ucfirst($stage->type) }}</span>
                                    <span class="text-xs text-gray-400 capitalize">{{ str_replace('_', ' ', $stage->status) }}</span>
                                </div>
                                @if($stage->completed_at && $stage->started_at)
                                    <span class="text-xs text-gray-400">
                                        {{ $stage->started_at->diffInSeconds($stage->completed_at) }}s
                                    </span>
                                @endif
                            </div>

                            @if($config['show_outputs'] && !empty($stage->output))
                                @php
                                    $outputText = is_array($stage->output)
                                        ? ($stage->output['summary'] ?? null)
                                        : $stage->output;
                                @endphp
                                @if($outputText)
                                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
                                        <p class="line-clamp-4">{{ $outputText }}</p>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Footer CTA --}}
        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-center text-sm text-gray-500">
            This experiment was shared using
            <a href="{{ route('home') }}" class="text-primary-600 hover:underline">{{ config('app.name') }}</a>.
            <a href="{{ route('login') }}" class="ml-2 text-primary-600 hover:underline">Sign in to run your own experiments →</a>
        </div>
    </div>
</x-layouts.public>
