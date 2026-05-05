<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search templates..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="categoryFilter">
            <option value="">All Categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('agents.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Create from Scratch
        </a>
    </div>

    {{-- Card Grid --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($templates as $template)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:shadow-md">
                <div class="mb-3 flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ $template['name'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $template['role'] }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                        {{ match($template['category'] ?? '') {
                            'engineering' => 'bg-blue-100 text-blue-800',
                            'content' => 'bg-purple-100 text-purple-800',
                            'business' => 'bg-green-100 text-green-800',
                            'design' => 'bg-pink-100 text-pink-800',
                            'research' => 'bg-amber-100 text-amber-800',
                            default => 'bg-gray-100 text-gray-800',
                        } }}">
                        {{ ucfirst($template['category'] ?? 'general') }}
                    </span>
                </div>

                <p class="mb-4 flex-1 text-sm text-gray-500 line-clamp-3">{{ $template['goal'] }}</p>

                @if(!empty($template['capabilities']))
                    <div class="mb-3 flex flex-wrap gap-1">
                        @foreach(array_slice($template['capabilities'], 0, 4) as $cap)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ str_replace('_', ' ', $cap) }}</span>
                        @endforeach
                        @if(count($template['capabilities']) > 4)
                            <span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-400">+{{ count($template['capabilities']) - 4 }}</span>
                        @endif
                    </div>
                @endif

                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <span>{{ $template['provider'] }}/{{ $template['model'] }}</span>
                        @if(!empty($template['skills']))
                            <span>{{ count($template['skills']) }} skills</span>
                        @endif
                    </div>

                    <button wire:click="useTemplate('{{ $template['slug'] }}')"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                        Use Template
                    </button>
                </div>
            </div>
        @empty
            <div class="col-span-full py-16 text-center">
                <i class="fa-solid fa-robot mx-auto text-4xl text-gray-300"></i>
                <p class="mt-4 text-sm text-gray-500">No templates match your search.</p>
            </div>
        @endforelse
    </div>
</div>
