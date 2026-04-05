<div @if($hasGenerating) wire:poll.5000ms @endif>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search websites...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('websites.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Website
        </a>
    </form>

    {{-- Grid --}}
    @if($websites->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <i class="fa-solid fa-globe text-4xl text-gray-300 mb-4"></i>
            <p class="text-sm text-gray-500 mb-4">No websites yet. Create your first one!</p>
            <a href="{{ route('websites.create') }}"
               class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Website
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($websites as $website)
                @if($website->status->value === 'generating')
                    {{-- Generating card --}}
                    <button wire:click="openProgress('{{ $website->id }}')"
                        class="flex flex-col rounded-xl border border-blue-200 bg-blue-50 p-5 shadow-sm text-left transition hover:shadow-md hover:border-blue-300 w-full">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
                                    <i class="fa-solid fa-globe animate-pulse"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">Generating website…</h3>
                                    <p class="text-xs text-gray-400">AI crew is working</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800">
                                <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                                Generating
                            </span>
                        </div>
                        <div class="mt-auto pt-3 border-t border-blue-100 text-xs text-blue-400">
                            <i class="fa-solid fa-circle-info mr-1"></i>Click to see progress
                        </div>
                    </button>
                @else
                    <a href="{{ route('websites.show', $website) }}"
                       class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-primary-300">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
                                    <i class="fa-solid fa-globe"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">{{ $website->name }}</h3>
                                    <p class="text-xs text-gray-400">{{ $website->slug }}</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                bg-{{ $website->status->color() }}-100 text-{{ $website->status->color() }}-800">
                                {{ $website->status->label() }}
                            </span>
                        </div>

                        <div class="mt-auto flex items-center justify-between pt-3 border-t border-gray-100 text-xs text-gray-400">
                            <span><i class="fa-solid fa-file-lines mr-1"></i>{{ $website->pages_count }} pages</span>
                            <span>{{ $website->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endif
            @endforeach
        </div>

        <div class="mt-6">
            {{ $websites->links() }}
        </div>
    @endif

    {{-- Progress modal --}}
    @if($progressWebsiteId)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40" wire:click.self="closeProgress">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Generation Progress</h3>
                    <button wire:click="closeProgress" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                @if($progressExecution)
                    <div class="mb-4 flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-700">Status:</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $progressExecution->status->value === 'completed' ? 'bg-green-100 text-green-800' : ($progressExecution->status->value === 'failed' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800') }}">
                            @if(!in_array($progressExecution->status->value, ['completed','failed']))
                                <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                            @endif
                            {{ ucfirst($progressExecution->status->value) }}
                        </span>
                    </div>

                    @if($progressExecution->taskExecutions->isNotEmpty())
                        <div class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Tasks</p>
                            @foreach($progressExecution->taskExecutions as $task)
                                <div class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                    <div class="flex-shrink-0">
                                        @if($task->status->value === 'validated')
                                            <i class="fa-solid fa-circle-check text-green-500"></i>
                                        @elseif($task->status->value === 'failed')
                                            <i class="fa-solid fa-circle-xmark text-red-500"></i>
                                        @elseif(in_array($task->status->value, ['running', 'assigned', 'needs_revision']))
                                            <i class="fa-solid fa-spinner fa-spin text-sm text-blue-500"></i>
                                        @else
                                            <i class="fa-regular fa-circle text-gray-300"></i>
                                        @endif
                                    </div>
                                    <span class="text-sm text-gray-700 flex-1 truncate">{{ $task->title }}</span>
                                    <span class="text-xs text-gray-400 flex-shrink-0">{{ ucfirst($task->status->value) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Planning tasks…</p>
                    @endif

                    @if($progressExecution->status->value === 'completed')
                        <div class="mt-4 rounded-lg bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                            <i class="fa-solid fa-circle-check mr-1"></i>
                            Generation complete! Refresh the page to see your website.
                        </div>
                    @elseif($progressExecution->status->value === 'failed')
                        <div class="mt-4 rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                            <i class="fa-solid fa-circle-xmark mr-1"></i>
                            Generation failed. Please try again.
                        </div>
                    @endif
                @else
                    <p class="text-sm text-gray-500">Starting crew…</p>
                @endif
            </div>
        </div>
    @endif
</div>
