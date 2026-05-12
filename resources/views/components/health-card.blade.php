@props([
    'checks' => [],
    'title' => 'Infrastructure checks',
])

@php
    /**
     * Renders spatie/laravel-health check results as a status grid.
     *
     * Expected payload shape (produced by HealthPage::getSpatieChecks()):
     *   [{name, status, message, meta}]
     * status ∈ { 'ok', 'warning', 'failed', 'crashed', 'skipped' }.
     */
    $statusBadge = [
        'ok' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-100 text-amber-700 ring-amber-200',
        'failed' => 'bg-red-100 text-red-700 ring-red-200',
        'crashed' => 'bg-red-100 text-red-700 ring-red-200',
        'skipped' => 'bg-gray-100 text-gray-500 ring-gray-200',
    ];
@endphp

<div class="rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-200 px-4 py-3">
        <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>
        <p class="text-xs text-gray-500">Declarative health probes (database, Redis, queue, cache, disk, scheduler).</p>
    </div>

    @if(count($checks) === 0)
        <div class="px-4 py-6 text-center text-sm text-gray-500">No checks registered.</div>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach($checks as $check)
                <li class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-gray-700">{{ $check['name'] }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusBadge[$check['status']] ?? $statusBadge['skipped'] }}">
                                {{ strtoupper($check['status']) }}
                            </span>
                        </div>
                        @if(!empty($check['message']))
                            <div class="mt-1 text-xs text-gray-600">{{ $check['message'] }}</div>
                        @endif
                        @if(!empty($check['meta']))
                            <details class="mt-1 text-xs text-gray-500">
                                <summary class="cursor-pointer">meta</summary>
                                <pre class="mt-1 max-h-32 overflow-auto rounded bg-gray-50 p-2 font-mono text-[10px]">{{ json_encode($check['meta'], JSON_PRETTY_PRINT) }}</pre>
                            </details>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
