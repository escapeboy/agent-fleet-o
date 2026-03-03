<div>
    @if(empty($entries))
        <div class="rounded-xl border border-gray-200 bg-white p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
            <h3 class="mt-4 text-sm font-medium text-gray-900">No changelog entries found</h3>
            <p class="mt-1 text-sm text-gray-500">Check back after the next platform update.</p>
        </div>
    @else
        <div class="space-y-6">
            @foreach($entries as $index => $entry)
                <div id="{{ $entry['id'] }}" class="rounded-xl border border-gray-200 bg-white" x-data="{ open: {{ $index < 3 ? 'true' : 'false' }} }">
                    {{-- Version header --}}
                    <button
                        @click="open = !open"
                        class="flex w-full items-center justify-between px-6 py-4 text-left hover:bg-gray-50 transition-colors rounded-xl"
                    >
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-gray-900">
                                @if($entry['version'])
                                    v{{ $entry['version'] }}
                                @else
                                    Update
                                @endif
                            </h2>
                            @if($entry['date'])
                                <span class="text-sm text-gray-500">
                                    {{ $entry['date']->format('F j, Y') }}
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            {{-- Category badges --}}
                            @foreach($entry['sections'] as $name => $category)
                                @switch($category)
                                    @case('new')
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">New</span>
                                        @break
                                    @case('improved')
                                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">Improved</span>
                                        @break
                                    @case('fixed')
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Fixed</span>
                                        @break
                                    @case('security')
                                        <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">Security</span>
                                        @break
                                    @default
                                        <span class="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-600/20">{{ $name }}</span>
                                @endswitch
                            @endforeach

                            {{-- Collapse chevron --}}
                            <svg class="h-5 w-5 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                    </button>

                    {{-- Content --}}
                    <div x-show="open" x-collapse>
                        <div class="border-t border-gray-100 px-6 py-4">
                            <div class="prose prose-sm max-w-none text-gray-700 prose-headings:text-gray-900 prose-h3:text-base prose-h3:font-semibold prose-h3:mt-4 prose-h3:mb-2 prose-a:text-primary-600 prose-strong:text-gray-900 prose-li:my-0.5">
                                {!! $entry['content_html'] !!}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
