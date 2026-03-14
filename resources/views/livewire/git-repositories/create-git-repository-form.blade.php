<div class="mx-auto max-w-2xl">
    {{-- Step indicator --}}
    <div class="mb-8 flex items-center justify-between">
        @foreach([1 => 'Basics', 2 => 'Mode', 3 => 'Configuration'] as $s => $label)
            <div class="flex flex-1 items-center {{ $s < 3 ? 'after:mx-4 after:h-px after:flex-1 after:bg-gray-200 after:content-[\'\']' : '' }}">
                <div class="flex items-center gap-2">
                    <span @class([
                        'flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium',
                        'bg-primary-600 text-white' => $step === $s,
                        'bg-primary-100 text-primary-700' => $step > $s,
                        'bg-gray-100 text-gray-500' => $step < $s,
                    ])>
                        @if($step > $s)
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @else
                            {{ $s }}
                        @endif
                    </span>
                    <span @class([
                        'text-sm font-medium',
                        'text-primary-700' => $step >= $s,
                        'text-gray-400' => $step < $s,
                    ])>{{ $label }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-8">
        {{-- Step 1: Basics --}}
        @if($step === 1)
            <h2 class="mb-6 text-lg font-semibold text-gray-900">Repository Details</h2>

            <div class="space-y-5">
                <x-form-input
                    wire:model.live.debounce.400ms="url"
                    label="Repository URL"
                    type="url"
                    placeholder="https://github.com/org/repo"
                    required
                />

                <x-form-input
                    wire:model="name"
                    label="Display Name"
                    placeholder="my-repo"
                    required
                />

                <x-form-select wire:model="provider" label="Provider">
                    @foreach($providers as $p)
                        <option value="{{ $p->value }}">{{ $p->label() }}</option>
                    @endforeach
                </x-form-select>

                <x-form-input
                    wire:model="defaultBranch"
                    label="Default Branch"
                    placeholder="main"
                    required
                />
            </div>

            <div class="mt-8 flex justify-end">
                <button wire:click="nextStep"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                    Continue →
                </button>
            </div>
        @endif

        {{-- Step 2: Mode --}}
        @if($step === 2)
            <h2 class="mb-6 text-lg font-semibold text-gray-900">Select Access Mode</h2>

            <div class="space-y-4">
                @foreach($modes as $m)
                    <label @class([
                        'flex cursor-pointer items-start gap-4 rounded-xl border p-4 transition-colors',
                        'border-primary-500 bg-primary-50' => $mode === $m->value,
                        'border-gray-200 hover:border-gray-300' => $mode !== $m->value,
                    ])>
                        <input type="radio" wire:model="mode" value="{{ $m->value }}" class="mt-1 h-4 w-4 text-primary-600">
                        <div>
                            <p class="font-medium text-gray-900">{{ $m->label() }}</p>
                            <p class="mt-0.5 text-sm text-gray-500">{{ $m->description() }}</p>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="mt-8 flex justify-between">
                <button wire:click="prevStep"
                    class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ← Back
                </button>
                <button wire:click="nextStep"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                    Continue →
                </button>
            </div>
        @endif

        {{-- Step 3: Configuration --}}
        @if($step === 3)
            <h2 class="mb-6 text-lg font-semibold text-gray-900">Configuration</h2>

            <div class="space-y-5">
                {{-- Credential --}}
                <x-form-select wire:model="credentialId" label="Credential (optional)">
                    <option value="">— None —</option>
                    @foreach($credentials as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->credential_type }})</option>
                    @endforeach
                </x-form-select>

                {{-- Sandbox config --}}
                @if($mode === 'sandbox')
                    <div class="rounded-lg border border-purple-200 bg-purple-50 p-4">
                        <p class="mb-4 text-sm font-medium text-purple-800">Sandbox Settings</p>
                        <div class="space-y-4">
                            <x-form-select wire:model="sandboxProvider" label="Sandbox Provider" compact>
                                <option value="runpod">RunPod</option>
                            </x-form-select>
                            <x-form-input wire:model="sandboxInstanceType" label="Instance Type" placeholder="CPU" compact />
                            <x-form-checkbox wire:model="runTests" label="Run tests after applying changes" />
                            @if($runTests)
                                <x-form-input wire:model="testCommand" label="Test Command" placeholder="php artisan test" compact />
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Bridge config --}}
                @if($mode === 'bridge')
                    <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
                        <p class="mb-4 text-sm font-medium text-orange-800">Bridge Settings</p>
                        <div class="space-y-4">
                            <x-form-input wire:model="bridgeRepoName" label="Local Repo Name" :placeholder="$name" compact />
                            <x-form-input wire:model="bridgeWorkingDirectory" label="Working Directory" placeholder="/home/user/projects/my-repo" compact />
                        </div>
                    </div>
                @endif

                {{-- PR config --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <p class="mb-3 text-sm font-medium text-gray-700">Pull Request Settings</p>
                    <x-form-checkbox wire:model="requireApproval" label="Require human approval before merging PRs" />
                </div>
            </div>

            <div class="mt-8 flex justify-between">
                <button wire:click="prevStep"
                    class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ← Back
                </button>
                <button wire:click="save"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                    Connect Repository
                </button>
            </div>
        @endif
    </div>
</div>
