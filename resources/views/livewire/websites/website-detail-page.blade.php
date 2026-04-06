<div @if($website->isGenerating()) wire:poll.5000ms @endif>
    {{-- Header bar --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium
                bg-{{ $website->status->color() }}-100 text-{{ $website->status->color() }}-800">
                @if($website->isGenerating())
                    <i class="fa-solid fa-spinner fa-spin text-xs"></i>
                @endif
                {{ $website->status->label() }}
            </span>
            <span class="text-sm text-gray-400">{{ $website->slug }}</span>
            @if($website->custom_domain)
                <span class="text-sm text-gray-400">·</span>
                <span class="text-sm text-gray-400">{{ $website->custom_domain }}</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if($website->status->value === 'published')
                <button wire:click="unpublish"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Unpublish
                </button>
            @else
                <button wire:click="publish"
                    class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                    Publish
                </button>
            @endif

            <a href="{{ route('websites.export', $website) }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fa-solid fa-file-zipper mr-1"></i>Export ZIP
            </a>

            <button wire:click="startEditWebsite"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fa-solid fa-pencil mr-1"></i>Edit
            </button>

            <button wire:click="deleteWebsite"
                wire:confirm="Delete this website and all its pages? This cannot be undone."
                class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">
                <i class="fa-solid fa-trash mr-1"></i>Delete
            </button>
        </div>
    </div>

    {{-- Edit website modal --}}
    @if($editingWebsite)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold">Edit Website</h3>
                <form wire:submit="saveWebsite" class="space-y-4">
                    <x-form-input wire:model="editName" label="Name" required />
                    <x-form-input wire:model="editSlug" label="Slug" hint="Only lowercase letters, numbers, and hyphens." />
                    <x-form-input wire:model="editCustomDomain" label="Custom Domain" placeholder="www.example.com" />
                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Save
                        </button>
                        <button type="button" wire:click="$set('editingWebsite', false)"
                            class="text-sm text-gray-500 hover:text-gray-700">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Managing Crew section --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
            <h2 class="text-base font-semibold text-gray-900">Managing Crew</h2>
        </div>
        <div class="px-6 py-4">
            @if($website->managingCrew)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-2 w-2 rounded-full bg-green-500"></span>
                        <span class="font-medium text-gray-900">{{ $website->managingCrew->name }}</span>
                        <span class="text-xs text-gray-400">· Send a command below to trigger crew actions</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <select wire:model="assigningCrewId"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                            <option value="">— change crew —</option>
                            @foreach($availableCrews as $crew)
                                <option value="{{ $crew->id }}" @selected($crew->id === $website->managing_crew_id)>{{ $crew->name }}</option>
                            @endforeach
                        </select>
                        <button wire:click="assignCrew"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                            Change
                        </button>
                        <button wire:click="$set('assigningCrewId', '')" wire:click="assignCrew"
                            onclick="if(confirm('Unassign the managing crew?')) { @this.set('assigningCrewId', ''); @this.call('assignCrew'); }"
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                            Unassign
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-500">No managing crew assigned.</span>
                    <select wire:model="assigningCrewId"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option value="">— select a crew —</option>
                        @foreach($availableCrews as $crew)
                            <option value="{{ $crew->id }}">{{ $crew->name }}</option>
                        @endforeach
                    </select>
                    <button wire:click="assignCrew"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Assign
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Command Panel --}}
    @if($website->managingCrew)
        <div id="command-panel" class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Send Command</h2>
            </div>
            <div class="px-6 py-4 space-y-3">
                @if($commandError)
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <i class="fa-solid fa-circle-exclamation mr-1.5"></i>{{ $commandError }}
                    </div>
                @endif

                @if($commandCrewExecutionId)
                    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        <i class="fa-solid fa-circle-check mr-1.5"></i>
                        Command dispatched.
                        <a href="{{ route('crews.execute', ['crew' => $website->managing_crew_id]) }}" class="underline font-medium">
                            View execution
                        </a>
                    </div>
                @endif

                @if($commandPageId)
                    @php $commandPage = $website->pages->firstWhere('id', $commandPageId); @endphp
                    @if($commandPage)
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                <i class="fa-solid fa-file-lines"></i>
                                Page: {{ $commandPage->title }}
                                <button wire:click="clearCommandPage" class="ml-1 text-blue-600 hover:text-blue-900">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </span>
                        </div>
                    @endif
                @endif

                <textarea wire:model="command"
                    rows="3"
                    placeholder="e.g. Update the hero section for Black Friday sale..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>

                <div class="flex items-center gap-2">
                    <button wire:click="executeCommand"
                        wire:loading.attr="disabled"
                        wire:target="executeCommand"
                        @if(!$command) disabled @endif
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="executeCommand"><i class="fa-solid fa-terminal mr-1.5"></i>Run</span>
                        <span wire:loading wire:target="executeCommand"><i class="fa-solid fa-spinner fa-spin mr-1.5"></i>Sending...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Linked Projects section --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
            <h2 class="text-base font-semibold text-gray-900">Linked Projects</h2>
            @if(!$linkingProject)
                <button wire:click="$set('linkingProject', true)"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i class="fa-solid fa-link mr-1"></i>Link Project
                </button>
            @endif
        </div>

        @if($linkingProject)
            <div class="border-b border-gray-100 bg-gray-50 px-6 py-3">
                <div class="flex items-center gap-3">
                    <select wire:model="linkProjectId"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option value="">— select a project —</option>
                        @foreach($availableProjects as $project)
                            <option value="{{ $project->id }}">{{ $project->title }}</option>
                        @endforeach
                    </select>
                    <button wire:click="linkProject"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Link
                    </button>
                    <button wire:click="$set('linkingProject', false)"
                        class="text-sm text-gray-500 hover:text-gray-700">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="px-6 py-4">
            @if($website->projects->isEmpty())
                <p class="text-sm text-gray-400">No projects linked to this website yet.</p>
            @else
                <table class="w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <th class="pb-3">Project</th>
                            <th class="pb-3">Type</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($website->projects as $project)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 font-medium text-gray-900">
                                    <a href="{{ route('projects.show', $project) }}" class="hover:text-primary-600">
                                        {{ $project->title }}
                                    </a>
                                </td>
                                <td class="py-3 capitalize text-gray-500">{{ $project->type->value }}</td>
                                <td class="py-3 text-gray-500">{{ $project->status->value }}</td>
                                <td class="py-3 text-right">
                                    <button wire:click="unlinkProject('{{ $project->id }}')"
                                        wire:confirm="Unlink this project from the website?"
                                        class="text-xs text-red-400 hover:text-red-600">
                                        <i class="fa-solid fa-unlink mr-1"></i>Unlink
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Assets section --}}
    <div class="mb-6">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900">Assets</h2>
        </div>

        <form wire:submit="uploadAsset" class="mb-4 flex items-center gap-3">
            <input type="file" wire:model="newAsset" accept="image/*"
                class="block text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100">
            <button type="submit"
                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                <i class="fa-solid fa-upload mr-1"></i>Upload
            </button>
        </form>
        @error('newAsset') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

        @if($website->assets->isEmpty())
            <p class="text-sm text-gray-400">No assets yet. Upload images to use in your pages.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach($website->assets as $asset)
                    <div class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <img src="{{ $asset->url }}" alt="{{ $asset->filename }}"
                            class="h-24 w-full object-cover">
                        <div class="px-2 py-1.5">
                            <p class="truncate text-xs text-gray-500" title="{{ $asset->filename }}">{{ $asset->filename }}</p>
                            <p class="text-xs text-gray-400">{{ number_format($asset->size_bytes / 1024, 0) }} KB</p>
                        </div>
                        <button wire:click="deleteAsset('{{ $asset->id }}')"
                            wire:confirm="Delete this asset?"
                            class="absolute right-1 top-1 hidden rounded bg-red-500 px-1.5 py-0.5 text-xs text-white group-hover:block">
                            <i class="fa-solid fa-times"></i>
                        </button>
                        <button type="button"
                            onclick="navigator.clipboard.writeText('{{ $asset->url }}')"
                            class="absolute left-1 top-1 hidden rounded bg-gray-700/70 px-1.5 py-0.5 text-xs text-white group-hover:block"
                            title="Copy URL">
                            <i class="fa-solid fa-copy"></i>
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Visual builder warning --}}


    {{-- Pages section --}}
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">Pages</h2>
        <button wire:click="$set('addingPage', true)"
            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
            <i class="fa-solid fa-plus mr-1"></i>Add Page
        </button>
    </div>

    {{-- Add page form --}}
    @if($addingPage)
        <div class="mb-4 rounded-xl border border-primary-200 bg-primary-50 p-4">
            <form wire:submit="addPage" class="space-y-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-40">
                        <x-form-input wire:model.live="newPageTitle" label="Title" placeholder="About Us" compact required />
                    </div>
                    <div class="flex-1 min-w-40">
                        <x-form-input wire:model="newPageSlug" label="Slug" placeholder="about-us" compact required />
                    </div>
                    <div class="min-w-32">
                        <x-form-select wire:model="newPageType" label="Type" compact>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                            <option value="product">Product</option>
                            <option value="landing">Landing</option>
                        </x-form-select>
                    </div>
                </div>
                <div>
                    <x-form-textarea
                        wire:model="newPageBrief"
                        label="Brief (optional)"
                        placeholder="Describe what this page should contain and do. The managing crew will generate the content based on your brief."
                        rows="3"
                        hint="{{ $website->managing_crew_id ? 'The managing crew will generate this page automatically.' : 'Assign a managing crew to auto-generate content from a brief.' }}"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        @if($website->managing_crew_id && trim($newPageBrief) !== '')
                            <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Add & Generate
                        @else
                            <i class="fa-solid fa-plus mr-1"></i>Add Page
                        @endif
                    </button>
                    <button type="button" wire:click="$set('addingPage', false)"
                        class="text-sm text-gray-500 hover:text-gray-700">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Pages list --}}
    @if($website->pages->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-12">
            <i class="fa-solid fa-file-lines text-3xl text-gray-300 mb-3"></i>
            <p class="text-sm text-gray-500">No pages yet. Add your first page to start building.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Page</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Content</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($website->pages->sortBy('sort_order') as $page)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div>
                                    <span class="font-medium text-gray-900">{{ $page->title }}</span>
                                    <p class="text-xs text-gray-400">/{{ $page->slug }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 capitalize">{{ $page->page_type->value }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $page->status->value === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $page->status->value === 'published' ? 'Published' : 'Draft' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                @if($page->exported_html)
                                    <span class="text-green-600"><i class="fa-solid fa-check mr-1"></i>Has content</span>
                                @else
                                    <span class="text-gray-300">Empty</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @if($website->managingCrew)
                                        <button wire:click="setCommandPage('{{ $page->id }}')"
                                            onclick="document.getElementById('command-panel')?.scrollIntoView({behavior:'smooth'})"
                                            title="Send command for this page"
                                            class="text-sm text-gray-400 hover:text-primary-600">
                                            <i class="fa-solid fa-terminal"></i>
                                        </button>
                                    @endif
                                    <a href="{{ route('websites.page.preview', [$website, $page]) }}"
                                        target="_blank"
                                        class="text-sm text-blue-500 hover:text-blue-700" title="Preview">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    @if($page->status->value === 'published')
                                        <button wire:click="unpublishPage('{{ $page->id }}')"
                                            class="text-sm text-amber-600 hover:text-amber-800" title="Unpublish">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </button>
                                    @else
                                        <button wire:click="publishPage('{{ $page->id }}')"
                                            class="text-sm text-green-600 hover:text-green-800" title="Publish">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    @endif
                                    <button wire:click="deletePage('{{ $page->id }}')"
                                        wire:confirm="Delete this page?"
                                        class="text-sm text-red-400 hover:text-red-600">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
