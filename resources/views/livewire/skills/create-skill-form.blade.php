<div class="mx-auto max-w-3xl">
    {{-- Progress Steps --}}
    <div class="mb-8 flex items-center justify-center space-x-4">
        @foreach(['Basics', 'Schema', 'Configuration', 'Review'] as $i => $label)
            <div class="flex items-center">
                <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium
                    {{ $step > $i + 1 ? 'bg-green-500 text-white' : ($step === $i + 1 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500') }}">
                    {{ $step > $i + 1 ? '&#10003;' : $i + 1 }}
                </div>
                <span class="ml-2 text-sm {{ $step === $i + 1 ? 'font-medium text-gray-900' : 'text-gray-500' }}">{{ $label }}</span>
                @if($i < 3)
                    <div class="mx-3 h-px w-12 bg-gray-300"></div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        {{-- Step 1: Basics --}}
        @if($step === 1)
            <div class="space-y-4">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. Lead Scorer"
                    :error="$errors->first('name')" />

                <x-form-textarea wire:model="description" label="Description" rows="3" placeholder="What does this skill do?" />

                <div class="grid grid-cols-2 gap-4">
                    <x-form-select wire:model="type" label="Type">
                        @foreach($types as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="riskLevel" label="Risk Level">
                        @foreach($riskLevels as $rl)
                            <option value="{{ $rl->value }}">{{ ucfirst($rl->value) }}</option>
                        @endforeach
                    </x-form-select>
                </div>
            </div>

        {{-- Step 2: Schema --}}
        @elseif($step === 2)
            <div class="space-y-6">
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Input Fields</h3>
                        <button wire:click="addInputField" class="text-sm text-primary-600 hover:text-primary-800">+ Add Field</button>
                    </div>
                    @foreach($inputFields as $i => $field)
                        <div class="mb-2 flex items-center gap-2 rounded border border-gray-200 p-2">
                            <input wire:model="inputFields.{{ $i }}.name" placeholder="Field name"
                                class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none" />
                            <select wire:model="inputFields.{{ $i }}.type"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 focus:outline-none">
                                <option value="string">String</option>
                                <option value="integer">Integer</option>
                                <option value="number">Number</option>
                                <option value="boolean">Boolean</option>
                                <option value="array">Array</option>
                                <option value="object">Object</option>
                            </select>
                            <label class="flex items-center gap-1 text-xs text-gray-500">
                                <input wire:model="inputFields.{{ $i }}.required" type="checkbox"
                                    class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /> Req
                            </label>
                            <button wire:click="removeInputField({{ $i }})" class="text-red-400 hover:text-red-600">&times;</button>
                        </div>
                    @endforeach
                    @if(empty($inputFields))
                        <p class="text-sm text-gray-400">No input fields defined. Click "Add Field" to start.</p>
                    @endif
                </div>

                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Output Fields</h3>
                        <button wire:click="addOutputField" class="text-sm text-primary-600 hover:text-primary-800">+ Add Field</button>
                    </div>
                    @foreach($outputFields as $i => $field)
                        <div class="mb-2 flex items-center gap-2 rounded border border-gray-200 p-2">
                            <input wire:model="outputFields.{{ $i }}.name" placeholder="Field name"
                                class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none" />
                            <select wire:model="outputFields.{{ $i }}.type"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 focus:outline-none">
                                <option value="string">String</option>
                                <option value="integer">Integer</option>
                                <option value="number">Number</option>
                                <option value="boolean">Boolean</option>
                                <option value="array">Array</option>
                                <option value="object">Object</option>
                            </select>
                            <button wire:click="removeOutputField({{ $i }})" class="text-red-400 hover:text-red-600">&times;</button>
                        </div>
                    @endforeach
                    @if(empty($outputFields))
                        <p class="text-sm text-gray-400">No output fields defined.</p>
                    @endif
                </div>
            </div>

        {{-- Step 3: Configuration --}}
        @elseif($step === 3)
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <x-form-select wire:model.live="provider" label="LLM Provider (optional)">
                        <option value="">Team Default</option>
                        @foreach($providers as $key => $providerConfig)
                            <option value="{{ $key }}">{{ $providerConfig['name'] }}</option>
                        @endforeach
                    </x-form-select>

                    @if($provider && isset($providers[$provider]))
                        <x-form-select wire:model="model" label="Model">
                            @foreach($providers[$provider]['models'] as $modelKey => $modelConfig)
                                <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                            @endforeach
                        </x-form-select>
                    @else
                        <x-form-input wire:model="model" label="Model (optional)" type="text" placeholder="e.g. claude-sonnet-4-5" />
                    @endif
                </div>

                @if(!empty($providers[$provider]['local']))
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                        Local agent — executes on the host machine using its own CLI process. No per-request API costs.
                    </div>
                @endif

                <x-form-textarea wire:model="systemPrompt" label="System Prompt" rows="5" :mono="true"
                    placeholder="Instruct the AI how to process input..." />

                <x-form-textarea wire:model="promptTemplate" label="Prompt Template (optional)" rows="3" :mono="true"
                    placeholder="Use @{{field_name}} for variable substitution"
                    hint="Leave empty to pass input as JSON." />

                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model.number="maxTokens" label="Max Tokens" type="number" min="100" max="8192" />
                    <x-form-input wire:model.number="temperature" label="Temperature" type="number" min="0" max="2" step="0.1" />
                </div>
            </div>

        {{-- Step 4: Review --}}
        @elseif($step === 4)
            <div class="space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Review & Create</h3>

                <dl class="divide-y divide-gray-100">
                    <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-gray-500">Name</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ $name }}</dd>
                    </div>
                    <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-gray-500">Type</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ ucfirst($type) }}</dd>
                    </div>
                    <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-gray-500">Risk Level</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ ucfirst($riskLevel) }}</dd>
                    </div>
                    <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-gray-500">Input Fields</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ count($inputFields) }} fields</dd>
                    </div>
                    <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-gray-500">Output Fields</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ count($outputFields) }} fields</dd>
                    </div>
                    @if($systemPrompt)
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">System Prompt</dt>
                            <dd class="max-h-24 overflow-auto text-sm text-gray-900 sm:col-span-2">{{ \Illuminate\Support\Str::limit($systemPrompt, 200) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif

        {{-- Navigation --}}
        <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
            @if($step > 1)
                <button wire:click="prevStep" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Back
                </button>
            @else
                <div></div>
            @endif

            @if($step < 4)
                <button wire:click="nextStep" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Next
                </button>
            @else
                <button wire:click="save" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Create Skill
                </button>
            @endif
        </div>
    </div>
</div>
