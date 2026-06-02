<div class="mx-auto max-w-2xl">
    <form wire:submit="save" class="space-y-6 rounded-xl border border-gray-200 bg-white p-6">
        <x-form-input wire:model="name" label="Policy name" required placeholder="e.g. Team default" />

        <x-form-select wire:model="agentId" label="Scope">
            <option value="">Team default (all agents)</option>
            @foreach($agents as $agent)
                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model="riskCeiling" label="Risk ceiling (max auto-approvable)">
            <option value="low">low</option>
            <option value="medium">medium</option>
            <option value="high">high</option>
        </x-form-select>

        <div class="rounded-lg border border-gray-200 p-4">
            <x-form-checkbox wire:model.live="autoExecuteEnabled" label="Allow auto-execute (opt-in)" />
            @if($autoExecuteEnabled)
                <div class="mt-3">
                    <x-form-input wire:model="autoExecuteThreshold" type="number" label="Rubric threshold (0–25)" />
                    <p class="mt-1 text-xs text-amber-600">Critical-risk actions always require a human regardless.</p>
                </div>
            @endif
        </div>

        <x-form-input wire:model="allowedTargetTypes" label="Allowed target types (comma-separated, blank = all)"
            placeholder="tool_call, integration_action, git_push" />

        <x-form-input wire:model="deniedTargetTypes" label="Denied target types (comma-separated)"
            placeholder="migration" />

        <x-form-textarea wire:model="sensitivePaths" label="Sensitive paths (one glob per line — forces review)"
            placeholder="app/**/Auth/**&#10;**/Billing/**" mono />

        <div class="grid grid-cols-2 gap-4">
            <x-form-input wire:model="spendCapCredits" type="number" label="Spend cap (credits, blank = none)" />
            <x-form-select wire:model="spendCapWindow" label="Spend window">
                <option value="hour">hour</option>
                <option value="day">day</option>
                <option value="week">week</option>
                <option value="month">month</option>
            </x-form-select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-form-input wire:model="frequencyCapCount" type="number" label="Frequency cap (actions, blank = none)" />
            <x-form-select wire:model="frequencyCapWindow" label="Frequency window">
                <option value="hour">hour</option>
                <option value="day">day</option>
                <option value="week">week</option>
                <option value="month">month</option>
            </x-form-select>
        </div>

        <x-form-checkbox wire:model="enabled" label="Enable this policy now" />

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('policies.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create policy
            </button>
        </div>
    </form>
</div>
