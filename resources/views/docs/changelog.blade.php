<x-layouts.docs
    title="Changelog"
    description="FleetQ release history — new features, improvements, and fixes."
    page="changelog"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Changelog</h1>
    <p class="mt-4 text-gray-600">
        New releases, features, and fixes — most recent first.
    </p>

    @php
        try {
            $parser = app(\App\Domain\System\Services\ChangelogParser::class);
            $entries = $parser->parse();
        } catch (\Throwable) {
            $entries = [];
        }
    @endphp

    @if(count($entries))
        <div class="mt-8 space-y-10">
            @foreach($entries as $entry)
                <div>
                    <h2 class="flex items-center gap-3 text-lg font-bold text-gray-900">
                        {{ $entry['version'] ?? '' }}
                        @if(!empty($entry['date']))
                            <span class="text-sm font-normal text-gray-400">{{ $entry['date'] }}</span>
                        @endif
                    </h2>
                    <div class="mt-3 prose prose-sm prose-gray max-w-none
                                prose-headings:font-semibold prose-headings:text-gray-800
                                prose-h3:text-base prose-h3:mt-5
                                prose-ul:mt-2 prose-li:my-0.5
                                prose-a:text-primary-600 hover:prose-a:underline">
                        {!! Str::markdown($entry['body'] ?? '') !!}
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="mt-8 rounded-xl border border-gray-200 bg-gray-50 p-8 text-center">
            <p class="text-sm text-gray-500">No changelog entries found.</p>
        </div>
    @endif
</x-layouts.docs>
