    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-4">
            <a href="{{ route('audit-console.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Audit Console</a>
            <h1 class="text-xl font-semibold text-gray-900">Audit Console Settings</h1>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        {{-- Usage stats --}}
        <div class="mb-6 grid grid-cols-2 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Runs this period</p>
                <p class="text-2xl font-semibold text-gray-900">
                    {{ $quota['used'] }}
                    <span class="text-sm font-normal text-gray-400">/ {{ $quota['limit'] === 'unlimited' ? '∞' : $quota['limit'] }}</span>
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Bundle storage</p>
                <p class="text-2xl font-semibold text-gray-900">{{ number_format($bundleStorageBytes / 1024 / 1024, 1) }} MB</p>
            </div>
        </div>

        <form wire:submit="save" class="space-y-6">
            <div class="space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">

                <x-form-checkbox wire:model="enabled" label="Enable Boruna Audit Console" />

                <x-form-checkbox wire:model="shadowMode" label="Shadow Mode (run alongside existing logic without enforcing)" />

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Workflows</p>
                    @foreach($workflows as $wf)
                        <div class="flex items-center gap-3 py-1">
                            <input type="checkbox"
                                   wire:model="workflowsEnabled.{{ $wf }}"
                                   id="wf_{{ $wf }}"
                                   class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            <label for="wf_{{ $wf }}" class="text-sm text-gray-700">{{ $wf }}</label>
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
                        class="rounded-md bg-primary-600 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-700">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
