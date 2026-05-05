<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('integrations.show', $integration) }}" class="text-sm text-primary-600 hover:underline">← Back to integration</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">Edit {{ $integration->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                Driver: <span class="font-medium">{{ $driver->label() }}</span>
                · Auth: <span class="font-medium">{{ $driver->authType()->label() }}</span>
            </p>
        </div>
    </div>

    @if($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {{ $errorMessage }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Name --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">General</h2>
            <x-form-input
                name="name"
                label="Integration name"
                wire:model="name"
                required
                hint="Display name shown across the platform."
            />
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Credentials --}}
        @if($driver->authType()->requiresCredentials() && !empty($credentialSchema))
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-1 text-lg font-semibold text-gray-900">Credentials</h2>
                <p class="mb-4 text-sm text-gray-500">
                    Leave password fields blank to keep the existing secret. Re-enter a value only when you want to change it.
                </p>
                <div class="space-y-4">
                    @foreach($credentialSchema as $key => $field)
                        @php
                            $type = $field['type'] ?? 'string';
                            $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $key));
                            $required = $field['required'] ?? false;
                            $hint = $field['hint'] ?? null;
                        @endphp

                        @if($type === 'password')
                            <x-form-input
                                :name="'credentials.'.$key"
                                :label="$label.($required ? ' *' : '')"
                                type="password"
                                :wire:model="'credentials.'.$key"
                                :hint="$hint ?? 'Leave blank to keep current value.'"
                                placeholder="••••••••"
                            />
                        @elseif($type === 'textarea')
                            <x-form-textarea
                                :name="'credentials.'.$key"
                                :label="$label.($required ? ' *' : '')"
                                :wire:model="'credentials.'.$key"
                                :hint="$hint"
                            />
                        @else
                            <x-form-input
                                :name="'credentials.'.$key"
                                :label="$label.($required ? ' *' : '')"
                                :wire:model="'credentials.'.$key"
                                :hint="$hint"
                            />
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Config (read-only summary; edit via API/MCP) --}}
        @if(!empty($config))
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-1 text-lg font-semibold text-gray-900">Driver Config</h2>
                <p class="mb-4 text-sm text-gray-500">
                    Driver-specific settings (read-only here). Update via the API
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">PUT /api/v1/integrations/{id}</code>
                    or the <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">integration_update</code> MCP tool.
                </p>
                <pre class="overflow-x-auto rounded bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($config, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif

        {{-- Options --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="reping" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                Re-ping the integration after saving (recommended)
            </label>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('integrations.show', $integration) }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </form>
</div>
