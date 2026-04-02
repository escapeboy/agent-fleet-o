<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">GPU Tool Templates</h2>
            <p class="mt-1 text-sm text-gray-500">Deploy pre-configured AI tools to GPU compute providers with 1-click.</p>
        </div>
        <a href="{{ route('tools.create') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create Custom Tool
        </a>
    </div>

    {{-- Provider Status Banner --}}
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-sm font-medium text-blue-900">Compute Providers</p>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach($providerStatus as $slug => $info)
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $info['configured'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                            @if($info['configured'])
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            @else
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            @endif
                            {{ $info['label'] }}
                        </span>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-blue-700">Configure API keys in <a href="{{ route('team.settings') }}" class="underline">Team Settings</a> to enable 1-click deployment.</p>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search templates..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="$set('category', '')"
                class="rounded-full px-3 py-1.5 text-xs font-medium {{ !$category ? 'bg-primary-100 text-primary-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                All
            </button>
            @foreach($categories as $cat)
                <button wire:click="$set('category', '{{ $cat->value }}')"
                    class="rounded-full px-3 py-1.5 text-xs font-medium {{ $category === $cat->value ? 'bg-primary-100 text-primary-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $cat->icon() }} {{ $cat->label() }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Template Grid --}}
    @if($templates->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-12 text-center">
            <p class="text-gray-500">No templates found{{ $search ? " matching \"{$search}\"" : '' }}.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach($templates as $template)
                <div class="group relative rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-300 hover:shadow-md">
                    @if($template->is_featured)
                        <span class="absolute -top-2 right-3 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Featured</span>
                    @endif

                    <div class="mb-3 flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">{{ $template->icon }}</span>
                            <div>
                                <h3 class="font-semibold text-gray-900">{{ $template->name }}</h3>
                                <span class="text-xs text-gray-500">{{ $template->category->label() }}</span>
                            </div>
                        </div>
                    </div>

                    <p class="mb-4 line-clamp-2 text-sm text-gray-600">{{ $template->description }}</p>

                    <div class="mb-4 flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            {{ $template->estimated_gpu ?? 'Any GPU' }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                            {{ $template->estimatedCostDisplay() }}
                        </span>
                        @if($template->license)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $template->license }}</span>
                        @endif
                    </div>

                    <div class="flex items-center justify-between">
                        @if($template->source_url)
                            <a href="{{ $template->source_url }}" target="_blank" rel="noopener" class="text-xs text-gray-400 hover:text-gray-600">
                                Source
                            </a>
                        @else
                            <span></span>
                        @endif

                        <button wire:click="openDeployModal('{{ $template->id }}')"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Deploy
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Deploy Modal --}}
    @if($showDeployModal && $selectedTemplate)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closeDeployModal">
            <div class="mx-4 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Deploy {{ $selectedTemplate->name }}</h3>
                    <button wire:click="closeDeployModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="mb-4 rounded-lg bg-gray-50 p-3">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">{{ $selectedTemplate->icon }}</span>
                        <div>
                            <p class="font-medium text-gray-900">{{ $selectedTemplate->name }}</p>
                            <p class="text-xs text-gray-500">{{ $selectedTemplate->category->label() }} &middot; {{ $selectedTemplate->estimated_gpu }} &middot; {{ $selectedTemplate->estimatedCostDisplay() }}</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Compute Provider</label>
                        <select wire:model="deployProvider" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($providerStatus as $slug => $info)
                                <option value="{{ $slug }}">
                                    {{ $info['label'] }} {{ $info['configured'] ? '(API key configured)' : '(no API key)' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Endpoint ID <span class="text-gray-400">(optional)</span></label>
                        <input wire:model="deployEndpointId" type="text" placeholder="e.g. abc123def456 (leave blank to configure later)"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <p class="mt-1 text-xs text-gray-500">If you already have a deployed endpoint on {{ $providerStatus[$deployProvider]['label'] ?? 'your provider' }}, enter its ID. Otherwise, leave blank and configure it after deployment.</p>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-xs text-amber-800">
                            <strong>Note:</strong> GPU tools run on your own {{ $providerStatus[$deployProvider]['label'] ?? 'provider' }} account. Costs are billed directly by the provider, not through FleetQ. The tool will be created as disabled until an endpoint ID is configured.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button wire:click="closeDeployModal" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="deploy" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Deploy Tool
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Flash messages --}}
    @if(session('message'))
        <div class="fixed bottom-4 right-4 z-50 rounded-lg bg-green-100 p-4 text-sm text-green-800 shadow-lg" x-data x-init="setTimeout(() => $el.remove(), 5000)">
            {{ session('message') }}
        </div>
    @endif
    @if(session('error'))
        <div class="fixed bottom-4 right-4 z-50 rounded-lg bg-red-100 p-4 text-sm text-red-800 shadow-lg" x-data x-init="setTimeout(() => $el.remove(), 5000)">
            {{ session('error') }}
        </div>
    @endif
</div>
