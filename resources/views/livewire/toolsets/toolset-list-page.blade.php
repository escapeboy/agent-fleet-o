<div>
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search toolsets...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>
        <a href="{{ route('toolsets.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Toolset
        </a>
    </form>

    @if($toolsets->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center">
            <p class="text-sm text-gray-500">No toolsets found. Create one to group tools for your agents.</p>
            <a href="{{ route('toolsets.create') }}" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Toolset
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($toolsets as $toolset)
                <a href="{{ route('toolsets.show', $toolset) }}"
                    class="block rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-300 hover:shadow-md">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <h3 class="font-semibold text-gray-900">{{ $toolset->name }}</h3>
                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs text-purple-700">
                            {{ count($toolset->tool_ids ?? []) }} tools
                        </span>
                    </div>
                    @if($toolset->description)
                        <p class="mb-3 line-clamp-2 text-sm text-gray-500">{{ $toolset->description }}</p>
                    @endif
                    <div class="flex flex-wrap gap-1">
                        @foreach($toolset->tags ?? [] as $tag)
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-gray-400">{{ $toolset->agents_count }} agent(s) using this</p>
                </a>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $toolsets->links() }}
        </div>
    @endif
</div>
