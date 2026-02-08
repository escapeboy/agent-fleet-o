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
        <div class="grid grid-cols-2 gap-4">
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
                            <span class="text-sm font-medium text-gray-900">{{ ucfirst($cred->provider) }}</span>
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
        <div class="flex items-end gap-3">
            <div>
                <x-form-select wire:model="credProvider" label="Provider">
                    @foreach($providers as $p)
                        <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                    @endforeach
                </x-form-select>
            </div>
            <div class="flex-1">
                <x-form-input wire:model="credApiKey" label="API Key" type="password" placeholder="sk-..." />
            </div>
            <button wire:click="addProviderCredential" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                Add Key
            </button>
        </div>
    </div>

    {{-- API Tokens --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">API Tokens</h2>
        <p class="mb-4 text-sm text-gray-500">Use API tokens to authenticate requests to the Agent Fleet API.</p>

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
        <div class="flex items-end gap-3">
            <div class="flex-1">
                <x-form-input wire:model="tokenName" label="Token Name" type="text" placeholder="e.g. CI/CD Pipeline"
                    :error="$errors->first('tokenName')" />
            </div>
            <button wire:click="createApiToken" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                Create Token
            </button>
        </div>
    </div>
</div>
