<div class="space-y-6">
    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif
    @if(session()->has('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Instance Info --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">Instance Settings</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-form-input wire:model="teamName" label="Instance Name" type="text" />
            <x-form-input wire:model="teamSlug" label="Slug" type="text" />
        </div>
        <button wire:click="saveTeamSettings" class="mt-4 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Save
        </button>
    </div>

    {{-- AI Provider Credentials (BYOK) --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">AI Provider Keys</h2>
        <p class="mb-4 text-sm text-gray-500">Add your API keys to use AI features. At least one provider key is required.</p>

        {{-- Existing credentials --}}
        @if($credentials->isNotEmpty())
            <div class="mb-4 space-y-2">
                @foreach($credentials as $cred)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2">
                        <div>
                            <span class="text-sm font-medium text-gray-900">{{ ($providerLabels[$cred->provider] ?? null) ?: ucfirst($cred->provider) }}</span>
                            <span class="ml-2 text-xs text-gray-500">Key configured</span>
                            @if($cred->is_active)
                                <span class="ml-2 inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">Active</span>
                            @endif
                        </div>
                        <button
                            wire:click="removeProviderCredential('{{ $cred->id }}')"
                            wire:confirm="Remove this API key?"
                            class="text-sm text-red-600 hover:text-red-800"
                        >
                            Remove
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add new credential --}}
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <x-form-select wire:model.live="credProvider" label="Provider">
                    @foreach($providers as $p)
                        <option value="{{ $p }}">{{ ($providerLabels[$p] ?? null) ?: ucfirst($p) }}</option>
                    @endforeach
                </x-form-select>
            </div>
            <div class="flex-1 min-w-0">
                <x-form-input wire:model="credApiKey" label="API Key" type="password" placeholder="sk-..." />
            </div>
            <button wire:click="addProviderCredential" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                Add Key
            </button>
        </div>
        <div class="mt-2 text-sm text-gray-500">
            @if($credProvider === 'groq')
                Get your free API key at <a href="https://console.groq.com/keys" target="_blank" class="text-primary-600 underline">console.groq.com</a> — no credit card required.
            @elseif($credProvider === 'openrouter')
                Get your free API key at <a href="https://openrouter.ai/keys" target="_blank" class="text-primary-600 underline">openrouter.ai</a> — 28 free models, no credit card.
            @elseif($credProvider === 'google')
                Get your free API key at <a href="https://aistudio.google.com/apikey" target="_blank" class="text-primary-600 underline">Google AI Studio</a> — just sign in with Google.
            @endif
        </div>
    </div>

    {{-- API Tokens --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">API Tokens</h2>
        <p class="mb-4 text-sm text-gray-500">Use API tokens to authenticate requests to the FleetQ API.</p>

        {{-- New token display --}}
        @if($newToken)
            <div class="mb-4 rounded-lg border border-green-300 bg-green-50 p-4">
                <p class="mb-2 text-sm font-medium text-green-800">Copy this token now. It won't be shown again.</p>
                <code class="block break-all rounded bg-white px-3 py-2 text-sm text-gray-900">{{ $newToken }}</code>
            </div>
        @endif

        {{-- Existing tokens --}}
        @if($apiTokens->isNotEmpty())
            <div class="mb-4 space-y-2">
                @foreach($apiTokens as $token)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2">
                        <div>
                            <span class="text-sm font-medium text-gray-900">{{ $token->name }}</span>
                            <span class="ml-2 text-xs text-gray-500">
                                Last used {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}
                            </span>
                        </div>
                        <button
                            wire:click="revokeApiToken({{ $token->id }})"
                            wire:confirm="Revoke this API token?"
                            class="text-sm text-red-600 hover:text-red-800"
                        >
                            Revoke
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Create token form --}}
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-0">
                <x-form-input wire:model="tokenName" label="Token Name" type="text" placeholder="e.g. CI/CD Pipeline"
                    :error="$errors->first('tokenName')" />
            </div>
            <button wire:click="createApiToken" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                Create Token
            </button>
        </div>
    </div>

    {{-- GPU Compute Providers --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">GPU Compute Providers</h2>
        <p class="mb-5 text-sm text-gray-500">
            Connect GPU cloud accounts to run AI inference via <strong>GPU Compute</strong> skills.
            Costs are billed directly to your provider account — not to FleetQ.
            Use the <code class="rounded bg-gray-100 px-1">compute_manage</code> MCP tool to manage credentials programmatically.
        </p>

        @php
        $computeProviderList = [
            'runpod'    => ['label' => 'RunPod',    'placeholder' => 'rpa_...', 'field' => 'runpodApiKey',    'save' => 'saveRunPodCredential',    'remove' => 'removeRunPodCredential',    'hint' => 'RunPod Console → Settings → API Keys'],
            'replicate' => ['label' => 'Replicate', 'placeholder' => 'r8_...', 'field' => 'replicateApiKey', 'save' => 'saveReplicateCredential', 'remove' => 'removeReplicateCredential', 'hint' => 'replicate.com → Account → API Tokens'],
            'fal'       => ['label' => 'Fal.ai',    'placeholder' => 'fal_...',  'field' => 'falApiKey',       'save' => 'saveFalCredential',       'remove' => 'removeFalCredential',       'hint' => 'fal.ai → Dashboard → API Keys'],
            'vast'      => ['label' => 'Vast.ai',   'placeholder' => 'vast_...',  'field' => 'vastApiKey',      'save' => 'saveVastCredential',      'remove' => 'removeVastCredential',      'hint' => 'vast.ai → Account → API Key'],
        ];
        @endphp

        <div class="space-y-4">
            @foreach($computeProviderList as $slug => $info)
                @php $cred = $computeCredentials->get($slug); @endphp
                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800">{{ $info['label'] }}</span>
                        @if($cred)
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Configured
                            </span>
                        @endif
                    </div>

                    @if($cred)
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-gray-500">API key is saved. Remove to replace it.</p>
                            <button wire:click="{{ $info['remove'] }}" wire:confirm="Remove the {{ $info['label'] }} API key?"
                                class="text-sm text-red-600 hover:text-red-800">
                                Remove
                            </button>
                        </div>
                    @else
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-0">
                                <x-form-input wire:model="{{ $info['field'] }}" label="API Key" type="password"
                                    placeholder="{{ $info['placeholder'] }}" hint="{{ $info['hint'] }}"
                                    :error="$errors->first($info['field'])" />
                            </div>
                            <button wire:click="{{ $info['save'] }}" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                                Save
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Local LLM Endpoints --}}
    @if($localLlmEnabled)
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Local LLM Endpoints</h2>
        <p class="mb-5 text-sm text-gray-500">
            Connect locally-running models via <strong>Ollama</strong> or any <strong>OpenAI-compatible</strong> endpoint
            (LM Studio, vLLM, llama.cpp, etc.). Inference runs on your hardware — no tokens are sent to external APIs.
        </p>

        {{-- Ollama --}}
        <div class="mb-4 rounded-lg border border-gray-100 p-4">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-800">Ollama</span>
                @if($localLlmCredentials->has('ollama'))
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Configured
                    </span>
                @endif
            </div>

            @if($localLlmCredentials->has('ollama'))
                @php $ollamaCred = $localLlmCredentials->get('ollama'); @endphp
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-500">
                        Endpoint: <code class="rounded bg-gray-100 px-1 text-xs">{{ $ollamaCred->credentials['base_url'] ?? '—' }}</code>
                    </p>
                    <button wire:click="removeOllamaCredential" wire:confirm="Remove the Ollama endpoint?"
                        class="text-sm text-red-600 hover:text-red-800">
                        Remove
                    </button>
                </div>
            @else
                <div class="space-y-3">
                    <x-form-input wire:model="ollamaBaseUrl" label="Base URL" type="url"
                        placeholder="http://localhost:11434"
                        hint="Root URL without /v1 — e.g. http://localhost:11434"
                        :error="$errors->first('ollamaBaseUrl')" />
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <x-form-input wire:model="ollamaApiKey" label="API Key (optional)" type="password"
                                placeholder="Leave empty for unauthenticated Ollama"
                                :error="$errors->first('ollamaApiKey')" />
                        </div>
                        <button wire:click="saveOllamaCredential"
                            class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                            Save
                        </button>
                    </div>
                </div>
            @endif
        </div>

        {{-- OpenAI-compatible --}}
        <div class="rounded-lg border border-gray-100 p-4">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-800">OpenAI-Compatible Endpoint</span>
                @if($localLlmCredentials->has('openai_compatible'))
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Configured
                    </span>
                @endif
            </div>
            <p class="mb-3 text-xs text-gray-500">Works with LM Studio, vLLM, llama.cpp server, Ollama OpenAI-compat mode, and similar.</p>

            @if($localLlmCredentials->has('openai_compatible'))
                @php $oaiCred = $localLlmCredentials->get('openai_compatible'); @endphp
                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-gray-500">
                            Endpoint: <code class="rounded bg-gray-100 px-1 text-xs">{{ $oaiCred->credentials['base_url'] ?? '—' }}</code>
                        </p>
                        <button wire:click="removeOpenaiCompatibleCredential" wire:confirm="Remove this endpoint?"
                            class="text-sm text-red-600 hover:text-red-800">
                            Remove
                        </button>
                    </div>
                    @if(!empty($oaiCred->credentials['models']))
                        <p class="text-xs text-gray-400">Models: {{ implode(', ', $oaiCred->credentials['models']) }}</p>
                    @endif
                </div>
            @else
                <div class="space-y-3">
                    <x-form-input wire:model="openaiCompatibleBaseUrl" label="Base URL" type="url"
                        placeholder="http://localhost:1234/v1"
                        hint="URL with /v1 suffix — e.g. http://localhost:1234/v1"
                        :error="$errors->first('openaiCompatibleBaseUrl')" />
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <x-form-input wire:model="openaiCompatibleApiKey" label="API Key (optional)" type="password"
                                placeholder="Leave empty if not required"
                                :error="$errors->first('openaiCompatibleApiKey')" />
                        </div>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <x-form-input wire:model="openaiCompatibleModels" label="Available Models (comma-separated)" type="text"
                                placeholder="meta-llama/Llama-3.2-3B-Instruct, mistral-7b-instruct"
                                hint="Model IDs as reported by the server's /models endpoint"
                                :error="$errors->first('openaiCompatibleModels')" />
                        </div>
                        <button wire:click="saveOpenaiCompatibleCredential"
                            class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                            Save
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Custom AI Endpoints --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Custom AI Endpoints</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Connect any OpenAI-compatible service — proxy servers, vLLM, LM Studio, OpenRouter, CCProxy, and more.
                </p>
            </div>
        </div>

        @if($customEndpoints->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-200 p-6 text-center">
                <p class="text-sm text-gray-500">No custom endpoints configured yet.</p>
                <p class="mt-1 text-xs text-gray-400">Any /v1/chat/completions-compatible endpoint works. Models you list here will appear in agent and skill provider dropdowns.</p>
            </div>
        @else
            <div class="mb-4 space-y-3">
                @foreach($customEndpoints as $ep)
                    <div class="flex items-start justify-between rounded-lg border border-gray-100 p-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-900">{{ $ep->name }}</span>
                                @if($ep->is_active)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span> Inactive
                                    </span>
                                @endif
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-400">{{ $ep->credentials['base_url'] ?? '—' }}</p>
                            @if(!empty($ep->credentials['models']))
                                <p class="mt-0.5 text-xs text-gray-400">Models: {{ implode(', ', $ep->credentials['models']) }}</p>
                            @endif
                        </div>
                        <div class="ml-3 flex shrink-0 items-center gap-2">
                            <button wire:click="toggleCustomEndpoint('{{ $ep->id }}')"
                                class="text-xs text-gray-500 hover:text-gray-700">
                                {{ $ep->is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button wire:click="removeCustomEndpoint('{{ $ep->id }}')"
                                wire:confirm="Remove this custom endpoint? Agents using it will fail."
                                class="text-xs text-red-600 hover:text-red-800">
                                Remove
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add form --}}
        <div x-data="{ open: false }" class="mt-4">
            <button @click="open = !open" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                <span x-show="!open">+ Add Endpoint</span>
                <span x-show="open">— Cancel</span>
            </button>

            <div x-show="open" x-cloak class="mt-4 space-y-3 rounded-lg border border-gray-100 p-4">
                <x-form-input wire:model="customEndpointName" label="Name (identifier)" type="text"
                    placeholder="my-openrouter"
                    hint="Lowercase letters, numbers, hyphens, underscores. Used to identify this endpoint in agent config."
                    :error="$errors->first('customEndpointName')" />
                <x-form-input wire:model="customEndpointBaseUrl" label="Base URL" type="url"
                    placeholder="https://openrouter.ai/api/v1"
                    hint="The /v1 endpoint root. Do not include /chat/completions."
                    :error="$errors->first('customEndpointBaseUrl')" />
                <x-form-input wire:model="customEndpointApiKey" label="API Key (optional)" type="password"
                    placeholder="sk-..."
                    hint="Leave empty for unauthenticated endpoints."
                    :error="$errors->first('customEndpointApiKey')" />
                <x-form-input wire:model="customEndpointModels" label="Available Models (comma-separated)" type="text"
                    placeholder="meta-llama/llama-3.3-70b-instruct, mistralai/mistral-7b-instruct"
                    hint="Model IDs exactly as the endpoint expects them."
                    :error="$errors->first('customEndpointModels')" />
                <div class="flex justify-end">
                    <button wire:click="addCustomEndpoint" @click="open = false"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Endpoint
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Portkey AI Gateway --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Portkey AI Gateway</h2>
        <p class="mb-5 text-sm text-gray-500">Route all LLM calls through Portkey for observability, caching, and fallbacks.</p>

        @php $portkeyCred = \App\Domain\Shared\Models\TeamProviderCredential::where('team_id', auth()->user()->current_team_id)->where('provider', 'portkey')->first(); @endphp

        @if($portkeyCred)
            <div class="mb-4 flex items-center justify-between rounded-lg bg-green-50 px-4 py-3">
                <div>
                    <p class="text-sm font-medium text-green-800">Portkey gateway configured</p>
                    <p class="mt-0.5 text-xs text-green-600">API key is set{{ $portkeyCred->credentials['virtual_key'] ? ' · Virtual key configured' : '' }}</p>
                </div>
                <button wire:click="removePortkeyConfig" wire:confirm="Remove Portkey configuration?"
                    class="text-sm text-red-600 hover:text-red-800">Remove</button>
            </div>
        @endif

        <div class="space-y-4">
            <x-form-input wire:model="portkeyApiKey" label="API Key" type="password"
                placeholder="pk-..." :error="$errors->first('portkeyApiKey')" />
            <x-form-input wire:model="portkeyVirtualKey" label="Virtual Key (optional)" type="password"
                placeholder="Leave empty to use direct routing"
                hint="Virtual keys let you swap underlying LLM providers without changing agent config."
                :error="$errors->first('portkeyVirtualKey')" />
            <div>
                <button wire:click="savePortkeyConfig"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save
                </button>
            </div>
        </div>
    </div>

    {{-- Credential Security (cloud only, Pro+ feature) --}}
    @if(isset($showKmsSecurity) && $showKmsSecurity)
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Credential Security</h2>
        <p class="mb-5 text-sm text-gray-500">
            Your credentials are encrypted with a dedicated per-team key. Pro and above plans can connect
            their own KMS for customer-managed encryption.
        </p>

        {{-- Encryption status --}}
        <div class="mb-5 flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Per-team encryption: Active
                </span>
            </div>
            <div class="flex items-center gap-2">
                @if($kmsConfig && $kmsConfig->status->value === 'active')
                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                        Customer KMS: {{ $kmsConfig->provider->label() }}
                    </span>
                @elseif($kmsConfig && $kmsConfig->status->value === 'error')
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Customer KMS: Error
                    </span>
                @else
                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-500">Customer KMS: Not configured</span>
                @endif
            </div>
        </div>

        @if($canUseKms ?? false)
            @if($kmsConfig && in_array($kmsConfig->status->value, ['active', 'error']))
                {{-- Active KMS display --}}
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-blue-900">{{ $kmsConfig->provider->label() }}</p>
                            <p class="mt-0.5 text-sm text-blue-700">
                                Key: <code class="rounded bg-blue-100 px-1 text-xs">{{ $kmsConfig->key_identifier }}</code>
                            </p>
                            <p class="mt-0.5 text-xs text-blue-600">
                                DEK wrapped: {{ $kmsConfig->dek_wrapped_at?->diffForHumans() ?? 'N/A' }}
                                @if($kmsConfig->last_used_at)
                                    · Last used: {{ $kmsConfig->last_used_at->diffForHumans() }}
                                @endif
                            </p>
                            @if($kmsEstimatedCost)
                                <p class="mt-1 text-xs text-blue-600">
                                    Estimated cost: ~${{ number_format($kmsEstimatedCost, 2) }}/month ({{ $kmsConfig->provider->label() }})
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="rewrapDek" wire:confirm="Re-wrap DEK with current CMK version?"
                                class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                Re-wrap DEK
                            </button>
                            <button wire:click="removeKms" wire:confirm="Remove KMS? Credentials will revert to platform encryption."
                                class="rounded px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">
                                Remove KMS
                            </button>
                        </div>
                    </div>
                </div>
            @else
                {{-- KMS setup form --}}
                <div class="rounded-lg border border-dashed border-gray-300 p-4">
                    <p class="mb-4 text-sm font-medium text-gray-700">Connect Your KMS</p>

                    <div class="mb-4">
                        <x-form-select wire:model.live="kmsProvider" label="KMS Provider">
                            <option value="">Select provider...</option>
                            <option value="aws_kms">AWS KMS</option>
                            <option value="gcp_kms">GCP Cloud KMS</option>
                            <option value="azure_key_vault">Azure Key Vault</option>
                        </x-form-select>
                    </div>

                    @if($kmsProvider === 'aws_kms')
                        <div class="space-y-3">
                            <x-form-input wire:model="kmsRoleArn" label="IAM Role ARN" type="text"
                                placeholder="arn:aws:iam::123456789012:role/FleetQKMSAccess"
                                :error="$errors->first('kmsRoleArn')" />
                            <x-form-input wire:model="kmsKeyArn" label="KMS Key ARN" type="text"
                                placeholder="arn:aws:kms:us-east-1:123456789012:key/mrk-..."
                                :error="$errors->first('kmsKeyArn')" />
                            <x-form-select wire:model="kmsRegion" label="Region">
                                @foreach(['us-east-1','us-east-2','us-west-1','us-west-2','eu-west-1','eu-west-2','eu-central-1','ap-southeast-1','ap-northeast-1'] as $r)
                                    <option value="{{ $r }}">{{ $r }}</option>
                                @endforeach
                            </x-form-select>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">External ID (for trust policy)</label>
                                <div class="flex items-center gap-2">
                                    <code class="flex-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ $kmsExternalId }}</code>
                                    <button onclick="navigator.clipboard.writeText('{{ $kmsExternalId }}')" type="button"
                                        class="rounded bg-gray-100 px-3 py-2 text-xs text-gray-600 hover:bg-gray-200">
                                        Copy
                                    </button>
                                </div>
                            </div>
                            <details class="rounded border border-gray-200 p-3">
                                <summary class="cursor-pointer text-sm font-medium text-gray-700">Setup Instructions</summary>
                                <div class="mt-3 text-xs text-gray-600">
                                    <p class="mb-2">1. Create an IAM Role in your AWS account with the following trust policy:</p>
                                    <pre class="mb-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs">{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"AWS": "arn:aws:iam::YOUR_FLEETQ_ACCOUNT:root"},
    "Action": "sts:AssumeRole",
    "Condition": {
      "StringEquals": {"sts:ExternalId": "{{ $kmsExternalId }}"}
    }
  }]
}</pre>
                                    <p class="mb-2">2. Attach a policy allowing <code>kms:Encrypt</code> and <code>kms:Decrypt</code> on your KMS key.</p>
                                    <p>3. Paste the Role ARN and Key ARN above, then test the connection.</p>
                                </div>
                            </details>
                        </div>
                    @elseif($kmsProvider === 'gcp_kms')
                        <div class="space-y-3">
                            <x-form-input wire:model="kmsGcpProjectId" label="Project ID" type="text"
                                placeholder="my-gcp-project" :error="$errors->first('kmsGcpProjectId')" />
                            <x-form-input wire:model="kmsGcpLocation" label="Location" type="text"
                                placeholder="us-east1" :error="$errors->first('kmsGcpLocation')" />
                            <x-form-input wire:model="kmsGcpKeyRing" label="Key Ring" type="text"
                                placeholder="my-key-ring" :error="$errors->first('kmsGcpKeyRing')" />
                            <x-form-input wire:model="kmsGcpKeyId" label="Key ID" type="text"
                                placeholder="my-crypto-key" :error="$errors->first('kmsGcpKeyId')" />
                            <x-form-textarea wire:model="kmsGcpServiceAccountJson" label="Service Account JSON" mono
                                placeholder="Paste your service account JSON key here..."
                                :error="$errors->first('kmsGcpServiceAccountJson')" />
                            <details class="rounded border border-gray-200 p-3">
                                <summary class="cursor-pointer text-sm font-medium text-gray-700">Setup Instructions</summary>
                                <div class="mt-3 text-xs text-gray-600">
                                    <p class="mb-2">1. Create a service account with the <code>Cloud KMS CryptoKey Encrypter/Decrypter</code> role.</p>
                                    <p class="mb-2">2. Create a JSON key for the service account and paste it above.</p>
                                    <p>3. Ensure the key ring and key exist in the specified project and location.</p>
                                </div>
                            </details>
                        </div>
                    @elseif($kmsProvider === 'azure_key_vault')
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <x-form-input wire:model="kmsAzureTenantId" label="Tenant ID" type="text"
                                    :error="$errors->first('kmsAzureTenantId')" />
                                <x-form-input wire:model="kmsAzureClientId" label="Client ID" type="text"
                                    :error="$errors->first('kmsAzureClientId')" />
                            </div>
                            <x-form-input wire:model="kmsAzureClientSecret" label="Client Secret" type="password"
                                :error="$errors->first('kmsAzureClientSecret')" />
                            <x-form-input wire:model="kmsAzureVaultUrl" label="Vault URL" type="url"
                                placeholder="https://my-vault.vault.azure.net"
                                :error="$errors->first('kmsAzureVaultUrl')" />
                            <div class="grid grid-cols-2 gap-3">
                                <x-form-input wire:model="kmsAzureKeyName" label="Key Name" type="text"
                                    :error="$errors->first('kmsAzureKeyName')" />
                                <x-form-input wire:model="kmsAzureKeyVersion" label="Key Version (optional)" type="text"
                                    placeholder="latest" />
                            </div>
                            <details class="rounded border border-gray-200 p-3">
                                <summary class="cursor-pointer text-sm font-medium text-gray-700">Setup Instructions</summary>
                                <div class="mt-3 text-xs text-gray-600">
                                    <p class="mb-2">1. Register an app in Azure AD and create a client secret.</p>
                                    <p class="mb-2">2. In your Key Vault, assign the <strong>Key Vault Crypto Officer</strong> role to the app.</p>
                                    <p>3. Create an RSA key in the vault and enter the details above.</p>
                                </div>
                            </details>
                        </div>
                    @endif

                    @if($kmsProvider)
                        {{-- Test result --}}
                        @if($kmsTestResult)
                            <div class="mt-4 rounded-lg p-3 text-sm {{ $kmsTestResult['success'] ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                {{ $kmsTestResult['message'] }}
                            </div>
                        @endif

                        <div class="mt-4 flex items-center gap-3">
                            <button wire:click="testKmsConnection" wire:loading.attr="disabled"
                                class="rounded-lg bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50">
                                <span wire:loading.remove wire:target="testKmsConnection">Test Connection</span>
                                <span wire:loading wire:target="testKmsConnection">Testing...</span>
                            </button>
                            @if($kmsTestResult && $kmsTestResult['success'])
                                <button wire:click="enableKms" wire:confirm="Enable KMS encryption? Your DEK will be wrapped with your KMS key."
                                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                                    Enable KMS
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        @else
            <x-plan-gate feature="customer_managed_keys" required-plan="Pro" mode="overlay"
                upgrade-message="Bring your own encryption keys (AWS KMS, GCP Cloud KMS, or Azure Key Vault).">
                <div class="rounded-lg border border-dashed border-gray-300 p-4">
                    <p class="mb-4 text-sm font-medium text-gray-700">Connect Your KMS</p>
                    <div class="mb-4">
                        <x-form-select label="KMS Provider" disabled>
                            <option value="">Select provider...</option>
                            <option value="aws_kms">AWS KMS</option>
                            <option value="gcp_kms">GCP Cloud KMS</option>
                            <option value="azure_key_vault">Azure Key Vault</option>
                        </x-form-select>
                    </div>
                </div>
            </x-plan-gate>
        @endif
    </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- FleetQ Bridge --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">FleetQ Bridge</h2>
        <p class="mb-4 text-sm text-gray-500">Connect your machines to FleetQ. Run <code class="rounded bg-gray-100 px-1 text-xs">fleetq-bridge serve</code> locally and expose it via a tunnel, or use the relay daemon.</p>

        @if($bridgeConnections->isNotEmpty())
            <div class="mb-4 space-y-3">
                @foreach($bridgeConnections as $conn)
                <div class="rounded-lg {{ $conn->isActive() ? 'bg-green-50' : 'bg-gray-50' }} p-4" wire:key="bridge-{{ $conn->id }}">
                    <div class="flex items-center justify-between">
                        <div class="min-w-0 flex-1"
                             x-data="{ editing: false, name: '{{ e($conn->label ?? '') }}' }">
                            <div x-show="!editing" class="flex items-center gap-2">
                                <span class="inline-block h-2 w-2 rounded-full {{ $conn->isActive() ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                <span class="font-medium {{ $conn->isActive() ? 'text-green-900' : 'text-gray-700' }}">
                                    @if($conn->isHttpMode())
                                        {{ $conn->label ?: parse_url($conn->endpoint_url, PHP_URL_HOST) ?: 'Bridge' }}
                                    @else
                                        {{ $conn->label ?? $conn->ip_address ?? 'Bridge' }}
                                    @endif
                                </span>
                                @if($conn->isHttpMode() && $conn->tunnel_provider)
                                    <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700">{{ ucfirst($conn->tunnel_provider) }}</span>
                                @endif
                                <button x-on:click="editing = true; $nextTick(() => $refs.nameInput.focus())"
                                    class="text-gray-400 hover:text-gray-600" title="Rename">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                </button>
                            </div>
                            <div x-show="editing" x-cloak class="flex items-center gap-2">
                                <input x-ref="nameInput" x-model="name"
                                    x-on:keydown.enter="$wire.renameBridge('{{ $conn->id }}', name); editing = false"
                                    x-on:keydown.escape="editing = false"
                                    class="rounded border border-gray-300 px-2 py-1 text-sm" placeholder="Bridge name">
                                <button x-on:click="$wire.renameBridge('{{ $conn->id }}', name); editing = false"
                                    class="text-xs text-primary-600 hover:text-primary-800">Save</button>
                                <button x-on:click="editing = false" class="text-xs text-gray-500">Cancel</button>
                            </div>
                            <p class="mt-0.5 text-sm {{ $conn->isActive() ? 'text-green-700' : 'text-gray-500' }}">
                                @if($conn->isHttpMode())
                                    HTTP tunnel
                                    @if($conn->last_seen_at) · Last seen {{ $conn->last_seen_at->diffForHumans() }} @endif
                                @else
                                    v{{ $conn->bridge_version ?? '?' }}
                                    @if($conn->ip_address && $conn->label) · {{ $conn->ip_address }} @endif
                                    @if($conn->connected_at) · {{ $conn->isActive() ? 'Connected' : 'Disconnected' }} {{ $conn->connected_at->diffForHumans() }} @endif
                                @endif
                            </p>
                        </div>
                        <div class="ml-3 flex shrink-0 items-center gap-2">
                            @if($conn->isHttpMode())
                                <button wire:click="pingBridge('{{ $conn->id }}')"
                                    class="rounded px-3 py-1 text-sm text-blue-600 hover:bg-blue-50">
                                    Ping
                                </button>
                            @endif
                            <button wire:click="disconnectBridge('{{ $conn->id }}')" wire:confirm="Disconnect this bridge?"
                                class="rounded px-3 py-1 text-sm text-red-600 hover:bg-red-50">
                                Remove
                            </button>
                        </div>
                    </div>

                    {{-- Discovered endpoints for this bridge --}}
                    @if($conn->isActive())
                        @php
                            $connAgents = collect($conn->agents())->filter(fn ($a) => $a['found'] ?? false);
                            $connLlms = collect($conn->llmEndpoints());
                            $connMcp = collect($conn->mcpServers());
                        @endphp
                        @if($connAgents->isNotEmpty() || $connLlms->isNotEmpty() || $connMcp->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach($connAgents as $agent)
                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">{{ $agent['name'] ?? $agent['key'] }}</span>
                            @endforeach
                            @foreach($connLlms as $llm)
                                <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs text-purple-700">{{ $llm['name'] ?? $llm['id'] }}</span>
                            @endforeach
                            @foreach($connMcp as $mcp)
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $mcp['name'] ?? $mcp['key'] }}</span>
                            @endforeach
                        </div>
                        @endif
                    @endif
                </div>
                @endforeach
            </div>

            @if($bridgeConnections->where('status', 'connected')->count() > 1)
                <button wire:click="disconnectAllBridges" wire:confirm="Disconnect all bridges?"
                    class="mb-3 text-xs text-red-600 hover:text-red-800">
                    Disconnect all
                </button>
            @endif

            {{-- Routing preferences (only show when 2+ bridges connected) --}}
            @if($bridgeConnections->count() > 1)
            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <h3 class="mb-3 text-sm font-medium text-gray-900">Agent Routing</h3>

                <x-form-select wire:model.live="bridgeRoutingMode" label="Routing Mode">
                    <option value="auto">Automatic — route to any online bridge</option>
                    <option value="prefer">Prefer a specific machine (fallback to others)</option>
                    <option value="per_agent">Per-agent routing (advanced)</option>
                </x-form-select>

                @if($bridgeRoutingMode === 'prefer')
                <div class="mt-3">
                    <x-form-select wire:model="preferredBridgeId" label="Preferred Bridge">
                        <option value="">Select...</option>
                        @foreach($bridgeConnections as $conn)
                            <option value="{{ $conn->id }}">
                                {{ $conn->label ?? ($conn->isHttpMode() ? parse_url($conn->endpoint_url, PHP_URL_HOST) : $conn->ip_address) ?? 'Bridge' }}
                                ({{ $conn->isActive() ? 'Online' : 'Offline' }})
                            </option>
                        @endforeach
                    </x-form-select>
                </div>
                @endif

                @if($bridgeRoutingMode === 'per_agent')
                <div class="mt-3 space-y-2 border-l-2 border-gray-200 pl-4">
                    @foreach($allBridgeAgents as $agent)
                    <x-form-select wire:model="agentRouting.{{ $agent['key'] }}" label="{{ $agent['name'] ?? $agent['key'] }}" compact>
                        <option value="auto">Auto (any bridge)</option>
                        @foreach($bridgeConnections->filter(fn($c) => collect($c->agents())->contains('key', $agent['key'])) as $conn)
                            <option value="{{ $conn->id }}">{{ $conn->label ?? ($conn->isHttpMode() ? parse_url($conn->endpoint_url, PHP_URL_HOST) : $conn->ip_address) ?? 'Bridge' }}</option>
                        @endforeach
                    </x-form-select>
                    @endforeach
                </div>
                @endif

                <div class="mt-3">
                    <button wire:click="saveBridgeRouting"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Save Routing
                    </button>
                </div>
            </div>
            @endif
        @else
            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-center">
                <p class="mb-1 text-sm font-medium text-gray-700">No Bridge Connected</p>
                <p class="mb-3 text-xs text-gray-500">Connect via URL (recommended) or download the relay daemon.</p>
            </div>
        @endif

        {{-- Connect via URL form --}}
        <div class="mt-4">
            @if(!$showConnectForm)
                <button wire:click="$set('showConnectForm', true)"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                    Connect via URL
                </button>
            @else
                <div class="rounded-lg border border-primary-200 bg-primary-50 p-4">
                    <h3 class="mb-1 text-sm font-medium text-gray-900">Connect via HTTP Tunnel</h3>
                    <p class="mb-3 text-xs text-gray-500">
                        Run <code class="rounded bg-white px-1">fleetq-bridge serve</code> locally, then expose it with
                        <code class="rounded bg-white px-1">cloudflared tunnel --url http://localhost:8765</code>
                        or <code class="rounded bg-white px-1">tailscale funnel 8765</code>.
                    </p>
                    <div class="space-y-3">
                        <x-form-input wire:model="connectUrl" label="Tunnel URL"
                            placeholder="https://abc123.trycloudflare.com"
                            :error="$errors->first('connectUrl')"
                            hint="The public HTTPS URL of your tunnel." />
                        <div class="grid grid-cols-2 gap-3">
                            <x-form-input wire:model="connectLabel" label="Label (optional)"
                                placeholder="Home Server" />
                            <x-form-select wire:model="connectTunnelProvider" label="Tunnel Provider">
                                <option value="cloudflare">Cloudflare Tunnel</option>
                                <option value="tailscale">Tailscale Funnel</option>
                                <option value="ngrok">ngrok</option>
                                <option value="other">Other</option>
                            </x-form-select>
                        </div>
                        <x-form-input wire:model="connectSecret" label="Secret Token (optional)"
                            type="password"
                            placeholder="Bearer token set with --secret flag"
                            hint="Leave blank if you didn't set --secret when starting fleetq-bridge serve." />
                        <div class="flex gap-2">
                            <button wire:click="connectViaUrl"
                                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                                Connect
                            </button>
                            <button wire:click="$set('showConnectForm', false)"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Telegram Bot --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Telegram Bot</h2>
        <p class="mb-4 text-sm text-gray-500">Connect a Telegram bot to receive messages as signals and chat with your AI assistant from Telegram.</p>

        @if($telegramBot)
            {{-- Connected bot info --}}
            <div class="mb-4 rounded-lg bg-green-50 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium text-green-900">
                            Connected: <span class="font-mono">@{{ $telegramBot->bot_username ?? $telegramBot->bot_name }}</span>
                        </p>
                        <p class="mt-0.5 text-sm text-green-700">
                            Routing: {{ ucwords(str_replace('_', ' ', $telegramBot->routing_mode)) }}
                            @if($telegramBot->last_message_at)
                                · Last message: {{ $telegramBot->last_message_at->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    <button wire:click="removeTelegramBot" wire:confirm="Disconnect the Telegram bot?"
                        class="rounded px-3 py-1 text-sm text-red-600 hover:bg-red-50">
                        Disconnect
                    </button>
                </div>
            </div>
            <p class="text-xs text-gray-500">To receive messages as signals, the platform polls this bot every minute. You can also <a href="{{ route('triggers.index') }}" class="text-primary-600 hover:underline">configure trigger rules</a> to auto-run projects when Telegram messages arrive.</p>
        @else
            <div class="space-y-3">
                <x-form-input wire:model="telegramBotToken" label="Bot Token" type="password"
                    placeholder="123456789:ABC..." hint="Create a bot via @BotFather on Telegram and paste the token here."
                    :error="$errors->first('telegramBotToken')" />
                <x-form-select wire:model="telegramRoutingMode" label="Routing Mode">
                    <option value="assistant">Route messages to AI Assistant</option>
                    <option value="project">Route messages to a Project</option>
                    <option value="trigger_rules">Match Trigger Rules only</option>
                </x-form-select>
                <div>
                    <button wire:click="saveTelegramBot" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Connect Bot
                    </button>
                </div>
            </div>
        @endif
    </div>
    </div>

    {{-- MCP Tool Preferences --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">MCP Tool Preferences</h2>
        <p class="mb-4 text-sm text-gray-500">Control which MCP tools are available to AI clients (Claude, Cursor, etc.) connecting to this team. Fewer tools = faster discovery and lower token usage.</p>

        {{-- Profile selector --}}
        <div class="mb-4">
            <label class="mb-1.5 block text-sm font-medium text-gray-700">Profile</label>
            <div class="flex flex-wrap gap-2">
                @foreach($mcpProfiles as $profileKey => $profileTools)
                    <button
                        wire:click="applyMcpProfile('{{ $profileKey }}')"
                        class="rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $mcpToolProfile === $profileKey ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
                    >
                        {{ ucfirst($profileKey) }}
                        <span class="ml-1 text-xs text-gray-500">
                            ({{ $profileTools === null ? 'all' : count($profileTools) }} tools)
                        </span>
                    </button>
                @endforeach
                <button
                    wire:click="$set('mcpToolProfile', 'custom')"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $mcpToolProfile === 'custom' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
                >
                    Custom
                </button>
            </div>
        </div>

        {{-- Tool groups with checkboxes --}}
        <div class="space-y-4" x-data>
            @foreach($mcpToolCatalog as $groupKey => $group)
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <h3 class="mb-1 text-sm font-semibold text-gray-800">{{ $group['label'] }}</h3>
                    <p class="mb-3 text-xs text-gray-500">{{ $group['description'] }}</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($group['tools'] as $toolName => $toolDescription)
                            <label class="flex items-start gap-2 rounded p-1.5 transition hover:bg-white">
                                <input
                                    type="checkbox"
                                    wire:model="mcpToolsEnabled"
                                    value="{{ $toolName }}"
                                    class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                    {{ $mcpToolProfile !== 'custom' ? 'disabled' : '' }}
                                />
                                <div>
                                    <span class="text-sm font-medium text-gray-800">{{ $toolName }}</span>
                                    <p class="text-xs text-gray-500">{{ $toolDescription }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button wire:click="saveMcpToolPreferences" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Save MCP Preferences
            </button>
            <span class="text-xs text-gray-500">
                {{ count($mcpToolsEnabled) }} / {{ collect($mcpToolCatalog)->sum(fn($g) => count($g['tools'])) }} tools enabled
            </span>
        </div>
    </div>

    {{-- Chatbot Feature --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Chatbot Feature</h2>
        <p class="mb-4 text-sm text-gray-500">Enable the Chatbots section to create and embed AI-powered chat widgets.</p>
        <div class="flex items-center gap-4">
            <x-form-checkbox wire:model="chatbotEnabled" label="Enable Chatbots for this team" />
        </div>
        <button wire:click="saveChatbotSettings" class="mt-4 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Save
        </button>
    </div>

    {{-- AI Features --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">AI Features</h2>
        <p class="mb-4 text-sm text-gray-500">Configure AI-powered automation features for your team.</p>

        <div class="space-y-4">
            {{-- Auto-Skill Proposals --}}
            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-2">Auto-Skill Proposals</h3>
                <p class="text-xs text-gray-500 mb-2">Automatically propose new skills from successful experiments with 5+ stages.</p>
                <x-form-checkbox wire:model="autoSkillProposeEnabled" label="Enable auto-skill proposals" />
                @if($autoSkillProposeEnabled)
                    <div class="mt-2 grid grid-cols-2 gap-4">
                        <x-form-input wire:model="autoSkillProposeMinStages" type="number" label="Min stages" hint="Minimum completed stages to trigger" compact />
                        <x-form-input wire:model="autoSkillProposeDailyCap" type="number" label="Daily cap" hint="Max proposals per day" compact />
                    </div>
                @endif
            </div>

            {{-- Context Compression --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Pipeline Context Compression</h3>
                <p class="text-xs text-gray-500 mb-2">Compress preceding stage outputs when they exceed the token threshold.</p>
                <x-form-checkbox wire:model="contextCompressionEnabled" label="Enable context compression" />
                @if($contextCompressionEnabled)
                    <div class="mt-2 max-w-xs">
                        <x-form-input wire:model="contextCompressionThreshold" type="number" label="Threshold (tokens)" hint="Compress when preceding context exceeds this" compact />
                    </div>
                @endif
            </div>

            {{-- Autonomous Evolution --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Autonomous Skill Evolution</h3>
                <p class="text-xs text-gray-500 mb-2">Automatically analyze agent executions and propose skill improvements.</p>
                <x-form-checkbox wire:model="autonomousEvolutionEnabled" label="Enable autonomous evolution" />
            </div>

            {{-- Hybrid Skill Retrieval --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Hybrid Skill Retrieval</h3>
                <p class="text-xs text-gray-500 mb-2">Use BM25 + semantic vector search to find the most relevant skills for agent tasks.</p>
                <x-form-checkbox wire:model="hybridRetrievalEnabled" label="Enable hybrid retrieval" />
            </div>

            {{-- Scout Phase --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Pre-Execution Scout Phase</h3>
                <p class="text-xs text-gray-500 mb-2">Run a lightweight LLM call before execution to identify what context the agent needs. Uses a cheap model (Haiku/mini/Flash).</p>
                <x-form-checkbox wire:model="scoutPhaseEnabled" label="Enable scout phase" />
            </div>

            {{-- Context Compaction --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Conversation Context Compaction</h3>
                <p class="text-xs text-gray-500 mb-2">Automatically compress agent conversation context when approaching the model's token limit.</p>
                <x-form-checkbox wire:model="contextCompactionEnabled" label="Enable context compaction" />
            </div>

            {{-- Experiment TTL --}}
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Experiment Time Limit</h3>
                <p class="text-xs text-gray-500 mb-2">Maximum wall-clock minutes an experiment may run before being killed.</p>
                <div class="max-w-xs">
                    <x-form-input wire:model="experimentTtlMinutes" type="number" label="TTL (minutes)" hint="5–1440 (24h)" compact />
                </div>
            </div>
        </div>

        <button wire:click="saveAiFeatures" class="mt-4 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Save AI Features
        </button>
    </div>

    {{-- Model Allowlist --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Model Allowlist</h2>
        <p class="mb-4 text-sm text-gray-500">Restrict which AI models your team can use. Leave empty to allow all models. Enter one <code class="rounded bg-gray-100 px-1 font-mono text-xs">provider/model</code> per line (e.g. <code class="rounded bg-gray-100 px-1 font-mono text-xs">anthropic/claude-sonnet-4-5</code>).</p>

        <x-form-textarea
            wire:model="allowedModelsInput"
            label="Allowed Models"
            hint="One provider/model per line. Empty = all models allowed."
            :rows="5"
            mono
        />

        <button wire:click="saveModelAllowlist" class="mt-4 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Save Allowlist
        </button>
    </div>

    {{-- Session TTL --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-gray-900">Session TTL</h2>
        <p class="mb-4 text-sm text-gray-500">Automatically expire assistant conversations after a period of inactivity. Set to 0 for no limit.</p>

        <x-form-input
            wire:model="maxSessionDurationMinutes"
            label="Max Session Duration (minutes)"
            type="number"
            min="0"
            max="10080"
            hint="0 = unlimited. Expired conversations start a new session on next message."
        />

        <button wire:click="saveSessionTtl" class="mt-4 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            Save Session TTL
        </button>
    </div>
</div>
