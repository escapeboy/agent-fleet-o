@php
    $total = ($counts['failed_24h'] ?? 0)
        + ($counts['stuck_now'] ?? 0)
        + ($counts['circuit_open'] ?? 0)
        + ($counts['paused'] ?? 0);
@endphp

<div wire:poll.60s="refresh">
    @if($total === 0)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <div class="flex items-start gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-semibold text-emerald-900">{{ __('All systems healthy') }}</h3>
                    <p class="mt-0.5 text-sm text-emerald-800">
                        {{ __('No failed, stuck, or paused experiments. No open circuit breakers.') }}
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
            <div class="flex items-start gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-100 text-rose-700">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold text-rose-900">{{ __('Attention needed') }}</h3>
                        <a
                            href="{{ route('health') }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-700"
                        >
                            <i class="fa-solid fa-stethoscope"></i>
                            {{ __('Triage now') }}
                        </a>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <div class="rounded-lg bg-white px-3 py-2">
                            <div class="text-xs text-rose-700">{{ __('Failed (24h)') }}</div>
                            <div class="text-lg font-semibold text-rose-900">{{ $counts['failed_24h'] ?? 0 }}</div>
                        </div>
                        <div class="rounded-lg bg-white px-3 py-2">
                            <div class="text-xs text-rose-700">{{ __('Stuck now') }}</div>
                            <div class="text-lg font-semibold text-rose-900">{{ $counts['stuck_now'] ?? 0 }}</div>
                        </div>
                        <div class="rounded-lg bg-white px-3 py-2">
                            <div class="text-xs text-rose-700">{{ __('Circuit open') }}</div>
                            <div class="text-lg font-semibold text-rose-900">{{ $counts['circuit_open'] ?? 0 }}</div>
                        </div>
                        <div class="rounded-lg bg-white px-3 py-2">
                            <div class="text-xs text-rose-700">{{ __('Paused') }}</div>
                            <div class="text-lg font-semibold text-rose-900">{{ $counts['paused'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
