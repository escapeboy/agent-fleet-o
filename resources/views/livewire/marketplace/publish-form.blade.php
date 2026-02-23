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
            <div class="mb-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">Visibility</label>
                <div class="flex gap-4">
                    <x-form-radio wire:model="visibility" value="public" label="Public" />
                    <x-form-radio wire:model="visibility" value="unlisted" label="Unlisted" />
                </div>
            </div>

            {{-- Provider Requirements (skills only) --}}
            @if($itemType === 'skill')
                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Required Providers</label>
                    <p class="mb-2 text-xs text-gray-500">Select providers this skill needs. Marketplace will show a compatibility badge to users.</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach(['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'google' => 'Google (Gemini)'] as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" wire:model="requiredProviders" value="{{ $key }}"
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Monetization --}}
            <div class="mb-6 rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Pay-Per-Run Monetization</label>
                        <p class="text-xs text-gray-500">Charge consumers per execution (platform takes 20%)</p>
                    </div>
                    <x-form-checkbox wire:model.live="monetizationEnabled" label="" />
                </div>
                @if($monetizationEnabled)
                    <div class="mt-3">
                        <x-form-input wire:model="pricePerRun" label="Price per run (credits)" type="number" min="0" step="0.0001"
                            :error="$errors->first('pricePerRun')" />
                        <p class="mt-1 text-xs text-gray-400">You receive {{ $pricePerRun > 0 ? number_format((float) $pricePerRun * 0.8, 2) : '0' }} credits per run after 20% platform fee.</p>
                    </div>
                @endif
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <a href="{{ route('app.marketplace.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
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
