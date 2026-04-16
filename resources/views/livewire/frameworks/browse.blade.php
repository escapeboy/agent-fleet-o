<div class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="max-w-6xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-gray-900">Frameworks</h1>
            <p class="mt-2 text-sm text-gray-600">
                Proven methodologies from product, sales, growth, finance, engineering, and operations — tag your skills by framework to keep them discoverable.
            </p>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <button
                type="button"
                wire:click="$set('category', '')"
                class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium ring-1 ring-inset
                    {{ $selectedCategory === null
                        ? 'bg-indigo-600 text-white ring-indigo-600'
                        : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50' }}"
            >
                All
            </button>
            @foreach ($categories as $cat)
                <button
                    type="button"
                    wire:click="$set('category', '{{ $cat->value }}')"
                    class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium ring-1 ring-inset
                        {{ $selectedCategory === $cat
                            ? 'bg-indigo-600 text-white ring-indigo-600'
                            : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50' }}"
                >
                    {{ $cat->label() }}
                </button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($frameworks as $entry)
                @php
                    $framework = $entry['framework'];
                    $count = $entry['skill_count'];
                @endphp
                <a
                    href="{{ route('skills.index', ['framework' => $framework->value]) }}"
                    class="block rounded-lg border border-gray-200 bg-white p-5 hover:border-indigo-300 hover:shadow-sm transition"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wider text-gray-500">
                                {{ $framework->category()->label() }}
                            </div>
                            <h3 class="mt-1 text-lg font-semibold text-gray-900">{{ $framework->label() }}</h3>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                            {{ $count }} {{ \Illuminate\Support\Str::plural('skill', $count) }}
                        </span>
                    </div>
                    <p class="mt-3 text-sm text-gray-600">{{ $framework->description() }}</p>
                </a>
            @endforeach
        </div>

        @if ($frameworks->isEmpty())
            <div class="text-center text-gray-500 py-12">
                No frameworks match this category.
            </div>
        @endif
    </div>
</div>
