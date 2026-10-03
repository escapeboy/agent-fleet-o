<div>
    <style>
        @media print {
            aside, nav, header, .no-print { display: none !important; }
            .print-break { break-inside: avoid; }
        }
    </style>

    {{-- Period --}}
    <form class="no-print mb-6 flex flex-wrap items-end gap-4" onsubmit="return false">
        <div>
            <x-form-input wire:model.live="from" type="date" label="From" compact />
        </div>
        <div>
            <x-form-input wire:model.live="to" type="date" label="To" compact />
        </div>
        <button type="button" onclick="window.print()"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i class="fa-solid fa-print"></i> Print / save as PDF
        </button>
    </form>

    @if($periodError)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $periodError }}</div>
    @endif

    @if($report)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-lg font-semibold text-gray-900">{{ $report['framework'] }}</h2>
            <p class="mt-1 text-sm text-gray-500">
                {{ \Illuminate\Support\Carbon::parse($report['period']['from'])->toDateString() }}
                &ndash;
                {{ \Illuminate\Support\Carbon::parse($report['period']['to'])->toDateString() }}
                &middot; generated {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->toDayDateTimeString() }}
            </p>
            <p class="mt-3 rounded-md bg-amber-50 p-3 text-xs text-amber-800">
                <i class="fa-solid fa-circle-info mr-1"></i>{{ $report['disclaimer'] }}
            </p>
        </div>

        <div class="space-y-4">
            @foreach($report['sections'] as $section)
                <section class="print-break rounded-xl border border-gray-200 bg-white p-5">
                    <div class="flex items-center gap-3">
                        <span class="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $section['article'] }}</span>
                        <h3 class="flex-1 text-base font-semibold text-gray-900">{{ $section['title'] }}</h3>
                        @if($section['status'] === 'evidence')
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                <i class="fa-solid fa-check"></i> Evidence
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                <i class="fa-solid fa-triangle-exclamation"></i> Gaps
                            </span>
                        @endif
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-sm md:grid-cols-2">
                        @foreach($section['evidence'] as $label => $value)
                            <div class="flex flex-col border-b border-gray-50 pb-2">
                                <dt class="text-xs text-gray-500">{{ \Illuminate\Support\Str::headline($label) }}</dt>
                                <dd class="mt-0.5 text-gray-900">
                                    @if(is_bool($value))
                                        {{ $value ? 'Yes' : 'No' }}
                                    @elseif(is_array($value))
                                        @forelse($value as $key => $item)
                                            <div class="font-mono text-xs">
                                                @if(is_array($item))
                                                    {{ implode(' · ', array_map('strval', $item)) }}
                                                @else
                                                    {{ $key }}: {{ $item }}
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-gray-400">none</span>
                                        @endforelse
                                    @else
                                        {{ $value ?? '—' }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    @if($section['gaps'])
                        <ul class="mt-4 space-y-1 text-sm text-amber-800">
                            @foreach($section['gaps'] as $gap)
                                <li><i class="fa-solid fa-triangle-exclamation mr-1 text-amber-500"></i>{{ $gap }}</li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
