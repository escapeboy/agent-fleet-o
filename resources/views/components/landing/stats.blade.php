<section id="stats" class="border-y border-gray-100 bg-gray-50">
    <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
        <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
            @php
                $stats = $stats ?? [
                    ['value' => '5', 'label' => 'AI Providers'],
                    ['value' => '9', 'label' => 'Workflow Node Types'],
                    ['value' => '120+', 'label' => 'MCP Tools'],
                    ['value' => '100%', 'label' => 'Open Source'],
                ];
            @endphp
            @foreach($stats as $stat)
                <div class="text-center"
                     x-data="{ shown: false }"
                     x-intersect.once="shown = true"
                     :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                     class="transition duration-500 ease-out"
                     style="transition-delay: {{ $loop->index * 100 }}ms">
                    <p class="text-3xl font-bold text-primary-600 sm:text-4xl">{{ $stat['value'] }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-600">{{ $stat['label'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
