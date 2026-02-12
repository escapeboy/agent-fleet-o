<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="space-y-6">
            {{-- Basics --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Basics</h3>
                <div class="space-y-4">
                    <x-form-input wire:model="title" label="Title" type="text" placeholder="e.g. Social Media Campaign"
                        :error="$errors->first('title')" />

                    <x-form-textarea wire:model="description" label="Description" rows="3"
                        placeholder="What should this project accomplish?"
                        :error="$errors->first('description')" />

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Project Type</label>
                            <div class="flex gap-3">
                                @foreach(\App\Domain\Project\Enums\ProjectType::cases() as $t)
                                    <button type="button" wire:click="$set('type', '{{ $t->value }}')"
                                        class="flex-1 rounded-lg border p-3 text-center text-sm transition
                                            {{ $type === $t->value ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:border-gray-300' }}">
                                        <div class="text-lg">{{ $t->icon() }}</div>
                                        <div class="mt-1 font-medium">{{ $t->label() }}</div>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <x-form-select wire:model="agentId" label="Lead Agent" :error="$errors->first('agentId')">
                            <option value="">Select an agent...</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->role ?? $agent->provider }})</option>
                            @endforeach
                        </x-form-select>
                    </div>
                </div>
            </div>

            {{-- Schedule (continuous only) --}}
            @if($type === 'continuous')
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Schedule</h3>
                    <div class="space-y-4 rounded-lg border border-blue-100 bg-blue-50/50 p-4">
                        <div class="grid grid-cols-3 gap-4">
                            <x-form-select wire:model.live="frequency" label="Frequency">
                                @foreach($frequencies as $freq)
                                    <option value="{{ $freq->value }}">{{ $freq->label() }}</option>
                                @endforeach
                            </x-form-select>

                            <x-form-select wire:model="timezone" label="Timezone">
                                @foreach(['UTC', 'Europe/Sofia', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Asia/Tokyo'] as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </x-form-select>

                            <x-form-select wire:model="overlapPolicy" label="Overlap Policy">
                                @foreach($overlapPolicies as $policy)
                                    <option value="{{ $policy->value }}">{{ $policy->label() }}</option>
                                @endforeach
                            </x-form-select>
                        </div>

                        @if($frequency === 'cron')
                            <x-form-input wire:model="cronExpression" label="Cron Expression" type="text"
                                placeholder="*/30 * * * *" hint="Standard cron syntax (min hour day month weekday)"
                                :error="$errors->first('cronExpression')" />
                        @endif

                        <x-form-input wire:model.number="maxConsecutiveFailures" label="Max Consecutive Failures" type="number"
                            min="1" max="100" hint="Auto-pause after this many consecutive failures" />
                    </div>
                </div>
            @endif

            {{-- Dependencies (Based on) --}}
            @if($availableProjects->isNotEmpty())
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Based on (optional)</h3>
                    <p class="mb-3 text-xs text-gray-500">Use results from previous projects as input context. The predecessor's artifacts and outputs will be injected into this project's pipeline.</p>

                    @foreach($dependencies as $index => $dep)
                        <div class="mb-3 flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3" wire:key="dep-{{ $index }}">
                            <div class="grid flex-1 grid-cols-3 gap-3">
                                <x-form-select wire:model.live="dependencies.{{ $index }}.depends_on_id" label="Project" compact>
                                    <option value="">Select project...</option>
                                    @foreach($availableProjects as $proj)
                                        <option value="{{ $proj->id }}">{{ $proj->title }}</option>
                                    @endforeach
                                </x-form-select>

                                <x-form-input wire:model="dependencies.{{ $index }}.alias" label="Alias" type="text"
                                    placeholder="e.g. research" compact />

                                <x-form-select wire:model="dependencies.{{ $index }}.reference_type" label="Use results from" compact>
                                    <option value="latest_run">Latest completed run</option>
                                    <option value="specific_run">Specific run</option>
                                </x-form-select>
                            </div>

                            <button wire:click="removeDependency({{ $index }})" type="button"
                                class="mt-6 rounded p-1 text-red-500 hover:bg-red-50">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    @endforeach

                    <button wire:click="addDependency" type="button"
                        class="mt-1 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:border-gray-400 hover:text-gray-700">
                        + Add Predecessor Project
                    </button>
                </div>
            @endif

            {{-- Budget --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Budget Caps (optional)</h3>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <x-form-input wire:model.number="perRunCap" label="Per Run" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="dailyCap" label="Daily" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="weeklyCap" label="Weekly" type="number" min="0" placeholder="No limit" />
                    <x-form-input wire:model.number="monthlyCap" label="Monthly" type="number" min="0" placeholder="No limit" />
                </div>
                <p class="mt-1.5 text-xs text-gray-500">Credits. Leave empty for unlimited. Project auto-pauses when a cap is hit and resumes when the period resets.</p>
            </div>

            {{-- Milestones --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Milestones (optional)</h3>
                @foreach($milestones as $index => $milestone)
                    <div class="mb-2 flex items-center gap-2" wire:key="milestone-{{ $index }}">
                        <span class="w-6 text-xs font-medium text-gray-400">{{ $index + 1 }}.</span>
                        <input type="text" wire:model="milestones.{{ $index }}.title" placeholder="Milestone title"
                            class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        <input type="number" wire:model.number="milestones.{{ $index }}.target_value" placeholder="Target"
                            class="w-24 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        <select wire:model="milestones.{{ $index }}.target_metric"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="runs">Runs</option>
                            <option value="credits">Credits</option>
                            <option value="custom">Custom</option>
                        </select>
                        <button wire:click="removeMilestone({{ $index }})" type="button"
                            class="rounded p-1 text-red-500 hover:bg-red-50">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @endforeach

                <button wire:click="addMilestone" type="button"
                    class="mt-1 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:border-gray-400 hover:text-gray-700">
                    + Add Milestone
                </button>
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <a href="{{ route('projects.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button wire:click="save" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Project
                </button>
            </div>
        </div>
    </div>
</div>
