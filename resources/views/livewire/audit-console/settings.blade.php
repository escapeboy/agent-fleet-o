    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center gap-4">
            <a href="{{ route('audit-console.index') }}" class="text-gray-400 hover:text-white text-sm">&larr; Audit Console</a>
            <h1 class="text-xl font-semibold text-white">Audit Console Settings</h1>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-900/40 border border-green-700 px-4 py-3 text-sm text-green-300">
                {{ session('success') }}
            </div>
        @endif

        {{-- Usage stats --}}
        <div class="mb-6 grid grid-cols-2 gap-4">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500 mb-1">Runs this period</p>
                <p class="text-2xl font-semibold text-white">
                    {{ $quota['used'] }}
                    <span class="text-sm font-normal text-gray-500">/ {{ $quota['limit'] === 'unlimited' ? '∞' : $quota['limit'] }}</span>
                </p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p class="text-xs text-gray-500 mb-1">Bundle storage</p>
                <p class="text-2xl font-semibold text-white">{{ number_format($bundleStorageBytes / 1024 / 1024, 1) }} MB</p>
            </div>
        </div>

        <form wire:submit="save" class="space-y-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6 space-y-5">

                <x-form-checkbox wire:model="enabled" label="Enable Boruna Audit Console" />

                <x-form-checkbox wire:model="shadowMode" label="Shadow Mode (run alongside existing logic without enforcing)" />

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-300">Workflows</p>
                    @foreach($workflows as $wf)
                        <div class="flex items-center gap-3 py-1">
                            <input type="checkbox"
                                   wire:model="workflowsEnabled.{{ $wf }}"
                                   id="wf_{{ $wf }}"
                                   class="rounded border-gray-600 bg-gray-700 text-primary-500 focus:ring-primary-500">
                            <label for="wf_{{ $wf }}" class="text-sm text-gray-300">{{ $wf }}</label>
                        </div>
                    @endforeach
                </div>

                <x-form-input wire:model="retentionDays"
                              label="Bundle Retention (days)"
                              type="number"
                              min="1"
                              max="3650" />

                <x-form-input wire:model="quotaPerMonth"
                              label="Monthly Run Quota (leave blank for unlimited)"
                              type="number"
                              min="1" />

            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="rounded-md bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700 transition-colors">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
