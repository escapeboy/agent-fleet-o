<div class="mx-auto max-w-3xl">
    {{-- Progress Steps --}}
    <div class="mb-8 flex items-center justify-center gap-2">
        @foreach([1 => 'Basics', 2 => 'Secret Data', 3 => 'Metadata', 4 => 'Review'] as $num => $label)
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium
                    {{ $step >= $num ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                    {{ $num }}
                </div>
                <span class="text-sm {{ $step >= $num ? 'text-gray-900' : 'text-gray-400' }}">{{ $label }}</span>
            </div>
            @if($num < 4)
                <div class="mx-2 h-px w-8 {{ $step > $num ? 'bg-primary-600' : 'bg-gray-200' }}"></div>
            @endif
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        {{-- Step 1: Basics --}}
        @if($step === 1)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Credential Basics</h3>
            <div class="space-y-4">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. Production API Key"
                    :error="$errors->first('name')" hint="A descriptive name for this credential" />

                <x-form-textarea wire:model="description" label="Description" rows="2"
                    placeholder="What is this credential used for?"
                    :error="$errors->first('description')" />

                <x-form-select wire:model.live="credentialType" label="Credential Type">
                    @foreach($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </x-form-select>

                @if($credentialType === 'basic_auth')
                    <p class="text-sm text-gray-500">Username and password authentication for websites and APIs.</p>
                @elseif($credentialType === 'api_token')
                    <p class="text-sm text-gray-500">Bearer token or API key for service authentication.</p>
                @elseif($credentialType === 'ssh_key')
                    <p class="text-sm text-gray-500">SSH private key for secure server access.</p>
                @elseif($credentialType === 'custom_kv')
                    <p class="text-sm text-gray-500">Custom key-value pairs for flexible credential storage.</p>
                @endif
            </div>
        @endif

        {{-- Step 2: Secret Data --}}
        @if($step === 2)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Secret Data</h3>
            <p class="mb-4 text-sm text-gray-500">All secret data is encrypted at rest using AES-256.</p>
            <div class="space-y-4">
                @if($credentialType === 'basic_auth')
                    <x-form-input wire:model="username" label="Username" type="text"
                        placeholder="user@example.com" :error="$errors->first('username')" />
                    <x-form-input wire:model="password" label="Password" type="password"
                        :error="$errors->first('password')" />
                    <x-form-input wire:model="loginUrl" label="Login URL (optional)" type="url"
                        placeholder="https://example.com/login" hint="The URL where these credentials are used" />
                @endif

                @if($credentialType === 'api_token')
                    <x-form-input wire:model="token" label="Token" type="password"
                        placeholder="sk-..." :error="$errors->first('token')" />
                    <x-form-select wire:model="tokenType" label="Token Type">
                        <option value="bearer">Bearer</option>
                        <option value="basic">Basic</option>
                        <option value="api_key">API Key</option>
                        <option value="custom">Custom</option>
                    </x-form-select>
                    <x-form-input wire:model="headerName" label="Header Name" type="text"
                        placeholder="Authorization" hint="HTTP header used for this token" />
                @endif

                @if($credentialType === 'ssh_key')
                    <x-form-textarea wire:model="privateKey" label="Private Key" rows="6" mono="true"
                        placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                        :error="$errors->first('privateKey')" />
                    <x-form-input wire:model="passphrase" label="Passphrase (optional)" type="password"
                        hint="If the key is passphrase-protected" />
                    <x-form-input wire:model="sshHost" label="Host (optional)" type="text"
                        placeholder="server.example.com" />
                @endif

                @if($credentialType === 'custom_kv')
                    <div class="space-y-3">
                        @foreach($customPairs as $i => $pair)
                            <div class="flex items-start gap-3">
                                <div class="flex-1">
                                    <x-form-input wire:model="customPairs.{{ $i }}.key" type="text"
                                        placeholder="Key" :error="$errors->first('customPairs.'.$i.'.key')" />
                                </div>
                                <div class="flex-1">
                                    <x-form-input wire:model="customPairs.{{ $i }}.value" type="password"
                                        placeholder="Value" :error="$errors->first('customPairs.'.$i.'.value')" />
                                </div>
                                @if(count($customPairs) > 1)
                                    <button wire:click="removeCustomPair({{ $i }})" type="button"
                                        class="mt-1 rounded p-1.5 text-red-400 hover:bg-red-50 hover:text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                        <button wire:click="addCustomPair" type="button"
                            class="text-sm font-medium text-primary-600 hover:text-primary-800">
                            + Add Pair
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Step 3: Metadata --}}
        @if($step === 3)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Metadata</h3>
            <div class="space-y-4">
                <x-form-input wire:model="expiresAt" label="Expires At (optional)" type="date"
                    hint="Leave blank if this credential does not expire" />
            </div>
        @endif

        {{-- Step 4: Review --}}
        @if($step === 4)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Review & Create</h3>
            <div class="space-y-3 text-sm">
                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <div class="text-gray-500">Name</div>
                    <div class="font-medium">{{ $name }}</div>

                    <div class="text-gray-500">Type</div>
                    <div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ \App\Domain\Credential\Enums\CredentialType::from($credentialType)->color() }}">
                            {{ \App\Domain\Credential\Enums\CredentialType::from($credentialType)->label() }}
                        </span>
                    </div>

                    @if($description)
                        <div class="text-gray-500">Description</div>
                        <div>{{ $description }}</div>
                    @endif

                    @if($credentialType === 'basic_auth')
                        <div class="text-gray-500">Username</div>
                        <div>{{ $username }}</div>
                        <div class="text-gray-500">Password</div>
                        <div class="text-gray-400">********</div>
                        @if($loginUrl)
                            <div class="text-gray-500">Login URL</div>
                            <div class="font-mono text-xs">{{ $loginUrl }}</div>
                        @endif
                    @elseif($credentialType === 'api_token')
                        <div class="text-gray-500">Token</div>
                        <div class="text-gray-400">{{ Str::mask($token, '*', 4) }}</div>
                        <div class="text-gray-500">Token Type</div>
                        <div>{{ ucfirst($tokenType) }}</div>
                        <div class="text-gray-500">Header</div>
                        <div class="font-mono text-xs">{{ $headerName }}</div>
                    @elseif($credentialType === 'ssh_key')
                        <div class="text-gray-500">Private Key</div>
                        <div class="text-gray-400">{{ Str::limit($privateKey, 40) }}</div>
                        @if($passphrase)
                            <div class="text-gray-500">Passphrase</div>
                            <div class="text-gray-400">********</div>
                        @endif
                        @if($sshHost)
                            <div class="text-gray-500">Host</div>
                            <div>{{ $sshHost }}</div>
                        @endif
                    @elseif($credentialType === 'custom_kv')
                        <div class="text-gray-500">Key-Value Pairs</div>
                        <div>{{ collect($customPairs)->filter(fn($p) => !empty($p['key']))->count() }} pairs</div>
                    @endif

                    @if($expiresAt)
                        <div class="text-gray-500">Expires</div>
                        <div>{{ $expiresAt }}</div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Actions --}}
        <div class="mt-6 flex justify-between">
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
                <button wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Credential
                </button>
            @endif
        </div>
    </div>
</div>
