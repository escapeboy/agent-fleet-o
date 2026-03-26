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
                    :error="$errors->first('name')"
                    toolparamdescription="Skill name — descriptive identifier" />

                <x-form-textarea wire:model="description" label="Description" rows="3" placeholder="What does this skill do?"
                    toolparamdescription="What this skill does and when to use it" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-select wire:model="type" label="Type" toolparamdescription="Skill type: llm, connector, rule, hybrid, or guardrail">
                        @foreach($types as $t)
                            @if($t->value !== 'browser' || $browserSkillEnabled)
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endif
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="riskLevel" label="Risk Level" toolparamdescription="Risk level: low, medium, high, or critical">
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
            @if($type === 'gpu_compute')
                <div class="space-y-4">
                    <div class="rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-800">
                        GPU Compute skills route inference requests to external GPU cloud providers. Costs are billed directly to your provider account.
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-select wire:model="computeProvider" label="GPU Provider">
                            @foreach($computeProviders as $slug => $info)
                                <option value="{{ $slug }}">{{ $info['label'] ?? $slug }}</option>
                            @endforeach
                        </x-form-select>

                        <x-form-input wire:model="computeEndpointId" label="Endpoint / Model ID" type="text"
                            placeholder="e.g. abc123def for RunPod" :error="$errors->first('computeEndpointId')" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-input wire:model="computeRoutePath" label="Route Path" type="text"
                            placeholder="/" hint="Path to append to worker URL (Vast.ai / custom). Use / for default." />

                        <x-form-input wire:model.number="computeTimeout" label="Timeout (seconds)" type="number"
                            min="10" max="600" />
                    </div>

                    <x-form-checkbox wire:model="computeUseSync" label="Use synchronous mode (recommended)" />
                </div>
            @elseif($type === 'runpod_endpoint')
                <div class="space-y-4">
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                        RunPod Serverless Endpoint — submits jobs to a RunPod endpoint and waits for the result. Billed per-second on RunPod.
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-input wire:model="runpodEndpointId" label="Endpoint ID" type="text"
                            placeholder="e.g. abc123def456" hint="Found in RunPod dashboard → Serverless → Endpoints" />
                        <x-form-input wire:model="runpodRoutePath" label="Route Path" type="text"
                            placeholder="/run" hint="Use /run for async or /runsync for sync" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-input wire:model.number="runpodTimeout" label="Timeout (seconds)" type="number" min="10" max="600" />
                    </div>
                    <x-form-checkbox wire:model="runpodUseSync" label="Use synchronous execution (/runsync)" />
                </div>
            @elseif($type === 'runpod_pod')
                <div class="space-y-4">
                    <div class="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-800">
                        RunPod GPU Pod — starts a dedicated GPU pod for the job and terminates it when done. Use for workloads requiring full control of the environment.
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-input wire:model="runpodDockerImage" label="Docker Image" type="text"
                            placeholder="e.g. runpod/pytorch:2.4.0-py3.11-cuda12.4.1" />
                        <x-form-select wire:model="runpodGpuType" label="GPU Type">
                            @foreach(array_keys(config('compute_providers.gpu_credits_per_hour', [])) as $gpu)
                                @if($gpu !== 'default')
                                    <option value="{{ $gpu }}">{{ $gpu }}</option>
                                @endif
                            @endforeach
                        </x-form-select>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-form-input wire:model.number="runpodGpuCount" label="GPU Count" type="number" min="1" max="8" />
                        <x-form-input wire:model.number="runpodContainerDiskGb" label="Container Disk (GB)" type="number" min="5" max="200" />
                        <x-form-input wire:model.number="runpodEstimatedMinutes" label="Est. Runtime (min)" type="number" min="1" max="180" hint="For cost tracking" />
                    </div>
                </div>
            @elseif($type === 'boruna_script')
                <div class="space-y-4">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Boruna Script — executes a custom Python/JS pipeline script inside a sandboxed environment.
                    </div>
                    <x-form-textarea wire:model="borunaScript" label="Script" rows="10" :mono="true"
                        placeholder="# Python script&#10;def run(input_data):&#10;    # Process input&#10;    return {'result': input_data}" />
                    <x-form-input wire:model.number="borunaScriptTimeout" label="Timeout (seconds)" type="number" min="5" max="300" />
                </div>
            @elseif($type === 'supabase_edge_function')
                <div class="space-y-4">
                    <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                        Supabase Edge Function — invokes a Deno function deployed to your Supabase project.
                    </div>
                    <x-form-input wire:model="supabaseProjectUrl" label="Project URL" type="text"
                        placeholder="https://xyzabc.supabase.co" />
                    <x-form-input wire:model="supabaseFunctionName" label="Function Name" type="text"
                        placeholder="e.g. my-function" hint="The function slug, not the full URL" />
                    <x-form-input wire:model="supabaseAnonKey" label="Anon Key" type="password"
                        hint="Supabase anon/public key — stored encrypted" />
                </div>
            @elseif($type === 'multi_model_consensus')
                <div class="space-y-4">
                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800">
                        Multi-Model Consensus — runs the same prompt across multiple LLMs and aggregates their responses.
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <label class="text-sm font-medium text-gray-700">Models to consult</label>
                            <button wire:click="addConsensusModel" class="text-sm text-primary-600 hover:text-primary-800">+ Add Model</button>
                        </div>
                        @foreach($consensusModels as $i => $cm)
                            <div class="mb-2 flex items-center gap-2 rounded border border-gray-200 p-2">
                                <x-form-select wire:model.live="consensusModels.{{ $i }}.provider" label="">
                                    <option value="">Provider...</option>
                                    @foreach($providers as $key => $providerConfig)
                                        <option value="{{ $key }}">{{ $providerConfig['name'] }}</option>
                                    @endforeach
                                </x-form-select>
                                @if(!empty($consensusModels[$i]['provider']) && isset($providers[$consensusModels[$i]['provider']]))
                                    <x-form-select wire:model="consensusModels.{{ $i }}.model" label="">
                                        @foreach($providers[$consensusModels[$i]['provider']]['models'] as $modelKey => $modelConfig)
                                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                                        @endforeach
                                    </x-form-select>
                                @else
                                    <input wire:model="consensusModels.{{ $i }}.model" type="text" placeholder="model ID"
                                           class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                @endif
                                <button wire:click="removeConsensusModel({{ $i }})" class="text-red-400 hover:text-red-600">&times;</button>
                            </div>
                        @endforeach
                        @if(empty($consensusModels))
                            <p class="text-sm text-gray-400">Add at least 2 models for consensus to work.</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-select wire:model="consensusAggregation" label="Aggregation Strategy">
                            <option value="majority">Majority vote</option>
                            <option value="average">Average (numeric)</option>
                            <option value="best_of">Best-of (by score)</option>
                            <option value="merge">Merge all responses</option>
                        </x-form-select>
                        <x-form-input wire:model.number="consensusThreshold" label="Consensus Threshold" type="number"
                            min="0" max="1" step="0.05" hint="Fraction of models that must agree (0.5 = majority)" />
                    </div>

                    <x-form-textarea wire:model="systemPrompt" label="System Prompt (applied to all models)" rows="4" :mono="true"
                        placeholder="Instruct all models how to process input..." />

                    <x-form-textarea wire:model="promptTemplate" label="Prompt Template (optional)" rows="2" :mono="true"
                        placeholder="Use @{{field_name}} for variable substitution" />
                </div>
            @else
                <div class="space-y-4">
                    {{-- Model selection mode toggle --}}
                    <x-form-checkbox wire:model.live="splitModelMode"
                        label="Use different model for design vs production"
                        hint="Build model: used when testing. Run model: used in production workflows." />

                    @if($splitModelMode)
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div class="rounded-lg border border-indigo-100 bg-indigo-50/50 p-3 space-y-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Build model <span class="font-normal normal-case">(design & testing)</span></p>
                                <x-form-select wire:model.live="buildProvider" label="Provider">
                                    <option value="">Team Default</option>
                                    @foreach($providers as $key => $providerConfig)
                                        <option value="{{ $key }}">{{ $providerConfig['name'] }}</option>
                                    @endforeach
                                </x-form-select>
                                @if($buildProvider && isset($providers[$buildProvider]))
                                    <x-form-select wire:model="buildModel" label="Model">
                                        @foreach($providers[$buildProvider]['models'] as $modelKey => $modelConfig)
                                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                                        @endforeach
                                    </x-form-select>
                                @else
                                    <x-form-input wire:model="buildModel" label="Model" type="text" placeholder="e.g. claude-opus-4-6" />
                                @endif
                            </div>

                            <div class="rounded-lg border border-green-100 bg-green-50/50 p-3 space-y-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-green-700">Run model <span class="font-normal normal-case">(production)</span></p>
                                <x-form-select wire:model.live="runProvider" label="Provider">
                                    <option value="">Team Default</option>
                                    @foreach($providers as $key => $providerConfig)
                                        <option value="{{ $key }}">{{ $providerConfig['name'] }}</option>
                                    @endforeach
                                </x-form-select>
                                @if($runProvider && isset($providers[$runProvider]))
                                    <x-form-select wire:model="runModel" label="Model">
                                        @foreach($providers[$runProvider]['models'] as $modelKey => $modelConfig)
                                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                                        @endforeach
                                    </x-form-select>
                                @else
                                    <x-form-input wire:model="runModel" label="Model" type="text" placeholder="e.g. claude-haiku-4-5" />
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                    @endif

                    <x-form-textarea wire:model="systemPrompt" label="System Prompt" rows="5" :mono="true"
                        placeholder="Instruct the AI how to process input..." />

                    <x-form-textarea wire:model="promptTemplate" label="Prompt Template (optional)" rows="3" :mono="true"
                        placeholder="Use @{{field_name}} for variable substitution"
                        hint="Leave empty to pass input as JSON." />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-input wire:model.number="maxTokens" label="Max Tokens" type="number" min="100" max="8192" />
                        <x-form-input wire:model.number="temperature" label="Temperature" type="number" min="0" max="2" step="0.1" />
                    </div>
                </div>
            @endif

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
                    @if($type === 'gpu_compute')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">GPU Provider</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $computeProvider }}</dd>
                        </div>
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">Endpoint ID</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $computeEndpointId ?: '—' }}</dd>
                        </div>
                    @elseif($type === 'runpod_endpoint')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">RunPod Endpoint</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $runpodEndpointId ?: '—' }}</dd>
                        </div>
                    @elseif($type === 'runpod_pod')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">Docker Image</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $runpodDockerImage ?: '—' }}</dd>
                        </div>
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">GPU</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $runpodGpuCount }}× {{ $runpodGpuType }}</dd>
                        </div>
                    @elseif($type === 'boruna_script')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">Script</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $borunaScript ? \Illuminate\Support\Str::limit($borunaScript, 80) : '—' }}</dd>
                        </div>
                    @elseif($type === 'supabase_edge_function')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">Function</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ $supabaseFunctionName ?: '—' }} @ {{ $supabaseProjectUrl ?: '—' }}</dd>
                        </div>
                    @elseif($type === 'multi_model_consensus')
                        <div class="py-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <dt class="text-sm font-medium text-gray-500">Models</dt>
                            <dd class="text-sm text-gray-900 sm:col-span-2">{{ count($consensusModels) }} model(s), {{ $consensusAggregation }}</dd>
                        </div>
                    @elseif($systemPrompt)
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

@script
<script>
if (window.FleetQWebMcp?.isAvailable()) {
    window.FleetQWebMcp.registerTool({
        name: 'create_skill',
        description: 'Create a new reusable AI skill with type, prompt, and LLM configuration',
        inputSchema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'Skill name — descriptive identifier' },
                description: { type: 'string', description: 'What this skill does and when to use it' },
                type: { type: 'string', description: 'Skill type: llm, connector, rule, hybrid, or guardrail' },
                risk_level: { type: 'string', description: 'Risk level: low, medium, high, or critical' },
                system_prompt: { type: 'string', description: 'System prompt instructing the AI how to process input' },
                prompt_template: { type: 'string', description: 'Prompt template with @{{field}} substitution (optional)' },
                provider: { type: 'string', description: 'LLM provider (optional, uses team default)' },
                model: { type: 'string', description: 'LLM model ID (optional)' },
                max_tokens: { type: 'number', description: 'Max output tokens (100-8192)' },
                temperature: { type: 'number', description: 'Temperature (0-2)' },
            },
            required: ['name', 'type'],
        },
        async execute(params) {
            $wire.set('name', params.name);
            if (params.description) $wire.set('description', params.description);
            $wire.set('type', params.type);
            if (params.risk_level) $wire.set('riskLevel', params.risk_level);
            if (params.system_prompt) $wire.set('systemPrompt', params.system_prompt);
            if (params.prompt_template) $wire.set('promptTemplate', params.prompt_template);
            if (params.provider) $wire.set('provider', params.provider);
            if (params.model) $wire.set('model', params.model);
            if (params.max_tokens) $wire.set('maxTokens', params.max_tokens);
            if (params.temperature !== undefined) $wire.set('temperature', params.temperature);
            $wire.set('step', 4);
            await $wire.save();
            return { success: true, message: 'Skill created' };
        },
    });
}
</script>
@endscript
