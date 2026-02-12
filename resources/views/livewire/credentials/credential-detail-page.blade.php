<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    @if($editing)
        {{-- ====== EDIT MODE ====== --}}
        <div class="rounded-xl border border-primary-200 bg-white p-6">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Credential</h3>

            <div class="space-y-4">
                <x-form-input wire:model="editName" label="Name" type="text"
                    :error="$errors->first('editName')" />

                <x-form-textarea wire:model="editDescription" label="Description" rows="2"
                    :error="$errors->first('editDescription')" />

                <x-form-input wire:model="editExpiresAt" label="Expires At" type="date"
                    hint="Leave blank if this credential does not expire" />
            </div>

            <div class="mt-6 flex gap-3">
                <button wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Changes
                </button>
                <button wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @else
        {{-- ====== VIEW MODE ====== --}}
        {{-- Header --}}
        <div class="mb-6 flex items-start justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900">{{ $credential->name }}</h2>
                    <x-status-badge :status="$credential->status->value" />
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $credential->credential_type->color() }}">
                        {{ $credential->credential_type->label() }}
                    </span>
                    @if($credential->isExpired())
                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                            Expired
                        </span>
                    @endif
                </div>
                @if($credential->description)
                    <p class="mt-1 text-sm text-gray-500">{{ $credential->description }}</p>
                @endif
            </div>

            <div class="flex gap-2">
                <button wire:click="toggleStatus"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $credential->status === \App\Domain\Credential\Enums\CredentialStatus::Active ? 'Disable' : 'Enable' }}
                </button>
                <button wire:click="startEdit"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Edit
                </button>
                <button wire:click="startRotate"
                    class="rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">
                    Rotate Secret
                </button>
                <button wire:click="deleteCredential" wire:confirm="Are you sure you want to delete this credential? It will be removed from all projects."
                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                    Delete
                </button>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-6 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                @foreach(['overview' => 'Overview', 'rotate' => 'Rotate Secret', 'projects' => 'Projects'] as $tab => $label)
                    <button wire:click="$set('activeTab', '{{ $tab }}')"
                        class="border-b-2 pb-3 text-sm font-medium transition
                            {{ $activeTab === $tab
                                ? 'border-primary-600 text-primary-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab: Overview --}}
        @if($activeTab === 'overview')
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Details</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Slug</dt>
                            <dd class="font-mono text-xs text-gray-700">{{ $credential->slug }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Type</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $credential->credential_type->color() }}">
                                    {{ $credential->credential_type->label() }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Status</dt>
                            <dd><x-status-badge :status="$credential->status->value" /></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Expires</dt>
                            <dd class="text-gray-700">
                                @if($credential->expires_at)
                                    <span class="{{ $credential->isExpired() ? 'text-red-600 font-medium' : '' }}">
                                        {{ $credential->expires_at->format('M j, Y') }}
                                    </span>
                                @else
                                    Never
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Created</dt>
                            <dd class="text-gray-700">{{ $credential->created_at->format('M j, Y H:i') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Usage</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Last Used</dt>
                            <dd class="text-gray-700">{{ $credential->last_used_at?->format('M j, Y H:i') ?? 'Never' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Last Rotated</dt>
                            <dd class="text-gray-700">{{ $credential->last_rotated_at?->format('M j, Y H:i') ?? 'Never' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Secret Fields</dt>
                            <dd class="text-gray-700">
                                @php $fields = $credential->credential_type->requiredSecretFields(); @endphp
                                @if(count($fields))
                                    {{ implode(', ', $fields) }}
                                @else
                                    Custom pairs
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        @endif

        {{-- Tab: Rotate Secret --}}
        @if($activeTab === 'rotate')
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                @if($rotating)
                    <h4 class="mb-2 text-lg font-semibold text-gray-900">Rotate Secret</h4>
                    <p class="mb-4 text-sm text-gray-500">Enter the new secret data. This will replace the existing secret entirely.</p>

                    <div class="space-y-4">
                        @if($credential->credential_type === \App\Domain\Credential\Enums\CredentialType::BasicAuth)
                            <x-form-input wire:model="rotateUsername" label="Username" type="text"
                                :error="$errors->first('rotateUsername')" />
                            <x-form-input wire:model="rotatePassword" label="Password" type="password"
                                :error="$errors->first('rotatePassword')" />
                            <x-form-input wire:model="rotateLoginUrl" label="Login URL (optional)" type="url" />
                        @endif

                        @if($credential->credential_type === \App\Domain\Credential\Enums\CredentialType::ApiToken)
                            <x-form-input wire:model="rotateToken" label="Token" type="password"
                                :error="$errors->first('rotateToken')" />
                            <x-form-select wire:model="rotateTokenType" label="Token Type">
                                <option value="bearer">Bearer</option>
                                <option value="basic">Basic</option>
                                <option value="api_key">API Key</option>
                                <option value="custom">Custom</option>
                            </x-form-select>
                            <x-form-input wire:model="rotateHeaderName" label="Header Name" type="text" />
                        @endif

                        @if($credential->credential_type === \App\Domain\Credential\Enums\CredentialType::SshKey)
                            <x-form-textarea wire:model="rotatePrivateKey" label="Private Key" rows="6" mono="true"
                                :error="$errors->first('rotatePrivateKey')" />
                            <x-form-input wire:model="rotatePassphrase" label="Passphrase (optional)" type="password" />
                            <x-form-input wire:model="rotateSshHost" label="Host (optional)" type="text" />
                        @endif

                        @if($credential->credential_type === \App\Domain\Credential\Enums\CredentialType::CustomKeyValue)
                            <div class="space-y-3">
                                @foreach($rotateCustomPairs as $i => $pair)
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1">
                                            <x-form-input wire:model="rotateCustomPairs.{{ $i }}.key" type="text" placeholder="Key" />
                                        </div>
                                        <div class="flex-1">
                                            <x-form-input wire:model="rotateCustomPairs.{{ $i }}.value" type="password" placeholder="Value" />
                                        </div>
                                        @if(count($rotateCustomPairs) > 1)
                                            <button wire:click="removeRotateCustomPair({{ $i }})" type="button"
                                                class="mt-1 rounded p-1.5 text-red-400 hover:bg-red-50 hover:text-red-600">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                                <button wire:click="addRotateCustomPair" type="button"
                                    class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                    + Add Pair
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button wire:click="rotateSecret" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                            Rotate Secret
                        </button>
                        <button wire:click="cancelRotate" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                @else
                    <div class="py-8 text-center">
                        <p class="mb-4 text-sm text-gray-500">Replace the current secret data with new values.</p>
                        <button wire:click="startRotate"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                            Start Rotation
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Tab: Projects --}}
        @if($activeTab === 'projects')
            <div class="rounded-xl border border-gray-200 bg-white">
                @if($projects->isEmpty())
                    <div class="px-6 py-12 text-center text-sm text-gray-400">
                        No projects are using this credential yet.
                    </div>
                @else
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Project</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($projects as $project)
                                <tr class="transition hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <a href="{{ route('projects.show', $project) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                            {{ $project->name }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4">
                                        <x-status-badge :status="$project->status->value" />
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ $project->type->label() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
</div>
