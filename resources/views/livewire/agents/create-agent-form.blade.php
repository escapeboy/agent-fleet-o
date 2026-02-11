<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. Research Assistant"
                    :error="$errors->first('name')" />
                <x-form-input wire:model="role" label="Role" type="text" placeholder="e.g. Lead Research Analyst"
                    :error="$errors->first('role')" />
            </div>

            <x-form-textarea wire:model="goal" label="Goal" rows="2" placeholder="What should this agent accomplish?"
                :error="$errors->first('goal')" />

            <x-form-textarea wire:model="backstory" label="Backstory (optional)" rows="3" placeholder="Background context for the agent..." />

            <div class="grid grid-cols-3 gap-4">
                <x-form-select wire:model.live="provider" label="Provider">
                    @foreach($providers as $key => $provider)
                        <option value="{{ $key }}">{{ $provider['name'] }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="model" label="Model">
                    @if(isset($providers[$this->provider]['models']))
                        @foreach($providers[$this->provider]['models'] as $modelKey => $modelConfig)
                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                        @endforeach
                    @endif
                </x-form-select>

                <x-form-input wire:model.number="budgetCapCredits" label="Budget Cap (credits)" type="number" min="0" placeholder="Leave empty for unlimited" />
            </div>

            @if(!empty($providers[$this->provider]['local']))
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                    Local agent — executes on the host machine using its own CLI process. No per-request API costs.
                </div>
            @endif

            {{-- Fallback Chain --}}
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Fallback Chain</label>
                <p class="mb-3 text-xs text-gray-500">If the primary provider fails or is rate-limited, requests fall through to fallbacks in order.</p>

                @foreach($fallbackChain as $index => $fallback)
                    <div class="mb-2 flex items-center gap-2" wire:key="fallback-{{ $index }}">
                        <span class="text-xs font-medium text-gray-400 w-6">{{ $index + 1 }}.</span>
                        <select wire:model.live="fallbackChain.{{ $index }}.provider" class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($providers as $key => $p)
                                <option value="{{ $key }}">{{ $p['name'] }}</option>
                            @endforeach
                        </select>
                        <select wire:model="fallbackChain.{{ $index }}.model" class="flex-1 rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($providers[$fallbackChain[$index]['provider'] ?? 'anthropic']['models'] ?? [] as $modelKey => $modelInfo)
                                <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                            @endforeach
                        </select>
                        <button wire:click="removeFallback({{ $index }})" type="button" class="rounded p-1 text-red-500 hover:bg-red-50">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @endforeach

                <button wire:click="addFallback" type="button" class="mt-1 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:border-gray-400 hover:text-gray-700">
                    + Add Fallback
                </button>
            </div>

            {{-- Skill Assignment --}}
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Assign Skills</label>
                @if($availableSkills->isNotEmpty())
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($availableSkills as $skill)
                            <button wire:click="toggleSkill('{{ $skill->id }}')"
                                class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition
                                    {{ in_array($skill->id, $selectedSkillIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                <div class="flex h-5 w-5 items-center justify-center rounded border {{ in_array($skill->id, $selectedSkillIds) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300' }}">
                                    @if(in_array($skill->id, $selectedSkillIds))
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    @endif
                                </div>
                                <div>
                                    <div class="font-medium">{{ $skill->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $skill->type->label() }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400">No active skills available. <a href="{{ route('skills.create') }}" class="text-primary-600 hover:underline">Create a skill first.</a></p>
                @endif
            </div>

            <div class="flex justify-end border-t border-gray-200 pt-4">
                <button wire:click="save" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Agent
                </button>
            </div>
        </div>
    </div>
</div>
