<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="space-y-6">
            {{-- Header --}}
            <div>
                <h3 class="mb-1 text-sm font-semibold uppercase tracking-wider text-gray-500">Schedule a Workflow</h3>
                <p class="text-xs text-gray-500">Set up a workflow to run automatically on a schedule. Results can be delivered via email, Slack, Telegram, or webhook.</p>
            </div>

            {{-- Basics --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Basics</h3>
                <div class="space-y-4">
                    <x-form-select wire:model.live="workflowId" label="Workflow" :error="$errors->first('workflowId')">
                        <option value="">Select a workflow...</option>
                        @foreach($workflows as $wf)
                            <option value="{{ $wf->id }}">
                                {{ $wf->name }}
                                @if($wf->estimated_cost_credits)
                                    (~{{ number_format($wf->estimated_cost_credits) }} credits)
                                @endif
                            </option>
                        @endforeach
                    </x-form-select>

                    <x-form-input wire:model="title" label="Title" type="text"
                        placeholder="e.g. Morning News Digest"
                        hint="Name for this scheduled task"
                        :error="$errors->first('title')" />

                    <x-form-textarea wire:model="description" label="Description (optional)" rows="2"
                        placeholder="Brief description of what this scheduled workflow does" />
                </div>
            </div>

            {{-- Schedule --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Schedule</h3>
                <div class="space-y-4 rounded-lg border border-blue-100 bg-blue-50/50 p-4">
                    <div class="grid grid-cols-3 gap-4">
                        <x-form-select wire:model.live="frequency" label="Frequency">
                            @foreach($frequencies as $freq)
                                @if($freq->value !== 'once')
                                    <option value="{{ $freq->value }}">{{ $freq->label() }}</option>
                                @endif
                            @endforeach
                        </x-form-select>

                        <x-form-select wire:model="timezone" label="Timezone">
                            @foreach(['UTC', 'Europe/Sofia', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Asia/Tokyo', 'Asia/Shanghai', 'Australia/Sydney'] as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-form-select>

                        <x-form-select wire:model="overlapPolicy" label="If Previous Still Running">
                            @foreach($overlapPolicies as $policy)
                                <option value="{{ $policy->value }}">{{ $policy->label() }}</option>
                            @endforeach
                        </x-form-select>
                    </div>

                    @if($frequency === 'cron')
                        <x-form-input wire:model="cronExpression" label="Cron Expression" type="text"
                            placeholder="0 9 * * 1-5" hint="min hour day month weekday — e.g. weekdays at 9am"
                            :error="$errors->first('cronExpression')" />
                    @endif

                    <x-form-input wire:model.number="maxConsecutiveFailures" label="Max Consecutive Failures" type="number"
                        min="1" max="100" hint="Auto-pause after this many consecutive failures" />
                </div>
            </div>

            {{-- Delivery --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Deliver Results</h3>
                <div class="space-y-4 rounded-lg border border-green-100 bg-green-50/50 p-4">
                    <div class="grid grid-cols-2 gap-4">
                        <x-form-select wire:model.live="deliveryChannel" label="Channel">
                            <option value="none">No delivery (view in dashboard)</option>
                            <option value="email">Email</option>
                            <option value="slack">Slack</option>
                            <option value="telegram">Telegram</option>
                            <option value="webhook">Webhook</option>
                        </x-form-select>

                        @if($deliveryChannel !== 'none')
                            <x-form-select wire:model="deliveryFormat" label="Format">
                                <option value="summary">Summary</option>
                                <option value="full">Full Output</option>
                            </x-form-select>
                        @endif
                    </div>

                    @if($deliveryChannel === 'email')
                        <x-form-input wire:model="deliveryTarget" label="Email Address" type="email"
                            placeholder="you@example.com"
                            :error="$errors->first('deliveryTarget')" />
                    @elseif($deliveryChannel === 'slack')
                        <x-form-input wire:model="deliveryTarget" label="Slack Channel"
                            placeholder="#general"
                            hint="Uses the Slack webhook configured in Settings"
                            :error="$errors->first('deliveryTarget')" />
                    @elseif($deliveryChannel === 'telegram')
                        <x-form-input wire:model="deliveryTarget" label="Telegram Chat ID"
                            placeholder="e.g. -1001234567890"
                            hint="Uses the Telegram bot configured in Settings"
                            :error="$errors->first('deliveryTarget')" />
                    @elseif($deliveryChannel === 'webhook')
                        <x-form-input wire:model="deliveryTarget" label="Webhook URL" type="url"
                            placeholder="https://your-service.com/webhook"
                            :error="$errors->first('deliveryTarget')" />
                    @endif
                </div>
            </div>

            {{-- Budget --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Budget Caps (optional)</h3>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <x-form-input wire:model.number="perRunCap" label="Per Run" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="dailyCap" label="Daily" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="weeklyCap" label="Weekly" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="monthlyCap" label="Monthly" type="number" min="0" placeholder="No limit" />
                </div>
                <p class="mt-1.5 text-xs text-gray-500">Credits. Auto-pauses when a cap is hit, auto-resumes when the period resets.</p>
            </div>

            {{-- Options --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Options</h3>
                <div class="space-y-3">
                    <x-form-checkbox wire:model="activateOnSave" label="Activate immediately on save" />
                    <x-form-checkbox wire:model="runImmediately" label="Run first execution immediately" />
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <a href="{{ route('workflows.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button wire:click="save" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    {{ $activateOnSave ? 'Create & Activate' : 'Create Schedule' }}
                </button>
            </div>
        </div>
    </div>
</div>
