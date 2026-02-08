<div>
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form wire:submit="publish" class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Publish to Marketplace</h3>

            {{-- Item Type --}}
            <div class="mb-4">
                <x-form-select wire:model.live="itemType" label="Item Type">
                    <option value="skill">Skill</option>
                    <option value="agent">Agent</option>
                    <option value="workflow">Workflow</option>
                </x-form-select>
            </div>

            {{-- Select Item --}}
            <div class="mb-4">
                <x-form-select wire:model.live="itemId" label="Select {{ ucfirst($itemType) }}"
                    :error="$errors->first('itemId')">
                    <option value="">Choose...</option>
                    @if($itemType === 'skill')
                        @foreach($skills as $skill)
                            <option value="{{ $skill->id }}">{{ $skill->name }}</option>
                        @endforeach
                    @elseif($itemType === 'agent')
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    @elseif($itemType === 'workflow')
                        @foreach($workflows as $wf)
                            <option value="{{ $wf->id }}">{{ $wf->name }}</option>
                        @endforeach
                    @endif
                </x-form-select>
            </div>

            {{-- Name --}}
            <div class="mb-4">
                <x-form-input wire:model="name" label="Marketplace Name" type="text" placeholder="A catchy name for the listing"
                    :error="$errors->first('name')" />
            </div>

            {{-- Description --}}
            <div class="mb-4">
                <x-form-textarea wire:model="description" label="Short Description" rows="3" placeholder="What does this skill/agent do?"
                    :error="$errors->first('description')" />
            </div>

            {{-- README --}}
            <div class="mb-4">
                <x-form-textarea wire:model="readme" label="README (optional)" rows="6" :mono="true"
                    placeholder="Detailed documentation, usage examples..." />
            </div>

            {{-- Category & Tags --}}
            <div class="mb-4 grid grid-cols-2 gap-4">
                <x-form-input wire:model="category" label="Category" type="text" placeholder="e.g. productivity, data, marketing" />
                <x-form-input wire:model="tagsInput" label="Tags (comma-separated)" type="text" placeholder="e.g. ai, email, automation" />
            </div>

            {{-- Visibility --}}
            <div class="mb-6">
                <label class="mb-1 block text-sm font-medium text-gray-700">Visibility</label>
                <div class="flex gap-4">
                    <x-form-radio wire:model="visibility" value="public" label="Public" />
                    <x-form-radio wire:model="visibility" value="unlisted" label="Unlisted" />
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <a href="{{ route('marketplace.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button type="submit"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove>Publish</span>
                    <span wire:loading>Publishing...</span>
                </button>
            </div>
        </div>
    </form>
</div>
