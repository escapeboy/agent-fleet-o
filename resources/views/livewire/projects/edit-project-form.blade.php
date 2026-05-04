<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="space-y-6">
            {{-- Basics --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Basics</h3>
                <div class="space-y-4">
                    <x-form-input wire:model="title" label="Title" type="text"
                        :error="$errors->first('title')" />

                    <x-form-textarea wire:model="description" label="Description" rows="3"
                        :error="$errors->first('description')" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-select wire:model="agentId" label="Lead Agent" :error="$errors->first('agentId')">
                            <option value="">Select an agent...</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->role ?? $agent->provider }})</option>
                            @endforeach
                        </x-form-select>

                        @if($workflows->isNotEmpty())
                            <x-form-select wire:model.live="workflowId" label="Workflow (optional)" :error="$errors->first('workflowId')">
                                <option value="">No workflow — agent-only execution</option>
                                @foreach($workflows as $wf)
                                    <option value="{{ $wf->id }}">{{ $wf->name }}</option>
                                @endforeach
                            </x-form-select>
                        @endif
                    </div>

                    @if($workflowId)
                        <p class="text-xs text-blue-600">With a workflow selected, the project will execute the workflow directly (skipping scoring/planning stages).</p>
                    @endif

                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                        <span class="text-xs font-medium text-gray-500">Project Type:</span>
                        <span class="ml-1 text-xs font-semibold text-gray-700">{{ $project->type->label() }}</span>
                        <span class="ml-2 text-xs text-gray-400">(cannot be changed after creation)</span>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">Execution Mode</label>
                        <div class="flex gap-3">
                            @foreach($executionModes as $mode)
                                <button type="button" wire:click="$set('executionMode', '{{ $mode->value }}')"
                                    class="flex-1 rounded-lg border p-3 text-center text-sm transition
                                        {{ $executionMode === $mode->value ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:border-gray-300' }}">
                                    <div class="text-lg">{{ $mode->icon() }}</div>
                                    <div class="mt-1 font-medium">{{ $mode->label() }}</div>
                                </button>
                            @endforeach
                        </div>
                        @if($executionMode === 'watcher')
                            <p class="mt-2 text-xs text-amber-600">Watcher mode restricts agents to safe/read-only tools. Write and destructive tools are filtered out at runtime.</p>
                        @elseif($executionMode === 'yolo')
                            <p class="mt-2 text-xs text-amber-600">YOLO mode skips automated testing and validation stages. Not available for critical-risk skills. Use for rapid prototyping.</p>
                        @else
                            <p class="mt-2 text-xs text-gray-500">Autonomous mode gives agents full access to all assigned tools.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Schedule (continuous only) --}}
            @if($project->isContinuous())
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Schedule</h3>
                    <div class="space-y-4 rounded-lg border border-blue-100 bg-blue-50/50 p-4">
                        {{-- Natural language schedule input --}}
                        <div x-data="nlScheduleParser()" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Schedule (natural language)
                                <span class="font-normal text-gray-400 ml-1">optional</span>
                            </label>
                            <input
                                type="text"
                                x-model="nlInput"
                                @input.debounce.300ms="parse()"
                                placeholder='e.g. "every Monday at 9am" or "daily at 6pm"'
                                class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <p x-show="preview" x-text="'→ ' + preview" class="mt-1 text-xs text-indigo-600"></p>
                            <p x-show="!preview && nlInput.length > 3" class="mt-1 text-xs text-gray-400">
                                Try: "daily at 9am", "every Monday at 6pm", "every 30 minutes", "weekdays at 8am"
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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

                        @if(!empty($schedulePreview))
                            <div class="rounded-lg border border-blue-200 bg-white p-3">
                                <p class="mb-2 text-xs font-medium text-gray-500">Next 5 Scheduled Runs</p>
                                <div class="space-y-1">
                                    @foreach($schedulePreview as $runTime)
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-gray-400">{{ $loop->iteration }}.</span>
                                            <span class="font-mono text-gray-700">{{ $runTime->format('Y-m-d H:i') }} UTC</span>
                                            <span class="text-gray-400">({{ $runTime->diffForHumans() }})</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Heartbeat Monitoring --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Heartbeat Monitoring</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Enable proactive monitoring — the agent periodically reviews system state and acts if something needs attention.</p>
                    </div>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input type="checkbox" wire:model.live="heartbeatEnabled" class="peer sr-only">
                        <div class="peer h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:ring-4 peer-focus:ring-primary-300 dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-primary-800"></div>
                    </label>
                </div>

                @if($heartbeatEnabled)
                <div class="space-y-4 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-select wire:model="heartbeatIntervalMinutes" label="Check Interval" :error="$errors->first('heartbeatIntervalMinutes')">
                            <option value="15">Every 15 minutes</option>
                            <option value="30">Every 30 minutes</option>
                            <option value="60">Every hour</option>
                            <option value="120">Every 2 hours</option>
                            <option value="240">Every 4 hours</option>
                        </x-form-select>

                        <x-form-input wire:model="heartbeatBudgetCap" label="Budget Cap per Check (credits)" type="number"
                            :hint="'Optional — limits cost per heartbeat turn'"
                            :error="$errors->first('heartbeatBudgetCap')" placeholder="No limit" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Context Sources</label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Select what data the agent reviews during each heartbeat check.</p>
                        <div class="flex flex-wrap gap-3">
                            @foreach(['signals' => 'Recent Signals', 'metrics' => 'Metrics', 'audit' => 'Audit Log', 'experiments' => 'Active Experiments'] as $key => $label)
                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm cursor-pointer transition-colors
                                {{ in_array($key, $heartbeatContextSources) ? 'bg-primary-50 border-primary-300 text-primary-700 dark:bg-primary-900/20 dark:border-primary-700 dark:text-primary-300' : 'bg-white text-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600' }}">
                                <input type="checkbox" wire:model.live="heartbeatContextSources" value="{{ $key }}" class="sr-only">
                                <span>{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Outbound Channels --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Outbound Channels</h3>
                <div class="space-y-4 rounded-lg border border-gray-100 bg-gray-50/50 p-4">
                    <p class="text-xs text-gray-500">Which channels can the AI use when sending results from this project?</p>
                    <div class="flex flex-wrap gap-4">
                        @foreach(['email' => 'Email', 'slack' => 'Slack', 'telegram' => 'Telegram', 'webhook' => 'Webhook'] as $ch => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="allowedOutboundChannels" value="{{ $ch }}"
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-6 border-t border-gray-200 pt-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="notifyOnSuccess"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            Notify on success
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="notifyOnFailure"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            Notify on failure
                        </label>
                    </div>
                </div>
            </div>

            {{-- Result Delivery --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Result Delivery (optional)</h3>
                <div class="space-y-4 rounded-lg border border-gray-100 bg-gray-50/50 p-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-form-select wire:model.live="deliveryChannel" label="Delivery Channel">
                            <option value="none">No delivery</option>
                            <option value="email">Email</option>
                            <option value="slack">Slack</option>
                            <option value="telegram">Telegram</option>
                            <option value="webhook">Webhook</option>
                        </x-form-select>

                        @if($deliveryChannel !== 'none')
                            <x-form-input wire:model="deliveryTarget" label="Target"
                                type="text"
                                placeholder="{{ match($deliveryChannel) {
                                    'email' => 'you@example.com',
                                    'slack' => 'https://hooks.slack.com/services/...',
                                    'telegram' => 'chat_id',
                                    'webhook' => 'https://your-webhook.com/endpoint',
                                    default => '',
                                } }}"
                                :error="$errors->first('deliveryTarget')" />
                        @endif
                    </div>

                    @if($deliveryChannel !== 'none')
                        <x-form-select wire:model="deliveryFormat" label="Format">
                            <option value="summary">Summary</option>
                            <option value="full">Full Output</option>
                            <option value="json">JSON</option>
                        </x-form-select>
                    @endif
                </div>
            </div>

            {{-- Tools & Credentials --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Tools & Credentials (optional)</h3>

                @if($tools->isNotEmpty())
                    <div class="mb-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700">Tools</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach($tools as $tool)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 text-sm transition
                                    {{ in_array($tool->id, $selectedToolIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <input type="checkbox" wire:model.live="selectedToolIds" value="{{ $tool->id }}" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                    <span class="font-medium text-gray-700">{{ $tool->name }}</span>
                                    <span class="text-xs text-gray-400">{{ $tool->type->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($credentials->isNotEmpty())
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Credentials</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach($credentials as $credential)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 text-sm transition
                                    {{ in_array($credential->id, $selectedCredentialIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <input type="checkbox" wire:model.live="selectedCredentialIds" value="{{ $credential->id }}" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                    <span class="font-medium text-gray-700">{{ $credential->name }}</span>
                                    <span class="text-xs text-gray-400">{{ $credential->credential_type->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
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
                <p class="mt-1.5 text-xs text-gray-500">Credits. Leave empty for unlimited. Project auto-pauses when a cap is hit and resumes when the period resets.</p>
            </div>

            {{-- Quality Gates --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Quality Gates</h3>
                <div class="space-y-3 rounded-lg border border-gray-100 bg-gray-50/50 p-4">
                    <x-form-checkbox wire:model="doneGateEnabled" label="Done-Condition Gate"
                        hint="When enabled, transitions to Completed run a Haiku-judged check that the work matches the externalized done_criteria. Override the judge model in project.settings.done_gate_judge if needed." />
                    <x-form-checkbox wire:model="doneGateKillSwitch" label="Kill switch (bypass gate)"
                        hint="Bypasses the Done-Condition Gate even when enabled. Use only to break out of false-negative judge loops." />
                </div>
                <p class="mt-1.5 text-xs text-gray-500">The gate prevents premature completions by re-checking against feature-list.json — see AGENTS.md workspace contract.</p>
            </div>

            {{-- Email Template --}}
            @if($emailTemplates->isNotEmpty())
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">Email Template (optional)</h3>
                    <div class="rounded-lg border border-gray-100 bg-gray-50/50 p-4">
                        <x-form-select wire:model="emailTemplateId" label="Outbound Email Template"
                            hint="When set, outbound emails from this project will use this template instead of raw content.">
                            <option value="">— No template —</option>
                            @foreach($emailTemplates as $tmpl)
                                <option value="{{ $tmpl->id }}">{{ $tmpl->name }}{{ $tmpl->subject ? ' ('.$tmpl->subject.')' : '' }}</option>
                            @endforeach
                        </x-form-select>
                        @if($emailTemplateId)
                            <p class="mt-2 text-xs text-blue-600">
                                Use <code class="rounded bg-blue-50 px-1">&#123;&#123;variable&#125;&#125;</code> tokens in your template to interpolate outbound payload fields.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Submit --}}
            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <a href="{{ route('projects.show', $project) }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button wire:click="save" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

@script
<script>
/**
 * Alpine.js component for parsing natural language schedule expressions into cron syntax.
 * Supports expressions like "every Monday at 9am", "daily at 6pm", "every 30 minutes", etc.
 * On successful parse, updates the Livewire cronExpression and frequency properties.
 */
function nlScheduleParser() {
    return {
        nlInput: '',
        preview: '',
        parse() {
            const input = this.nlInput.trim().toLowerCase();
            if (!input) { this.preview = ''; return; }

            let cron = null;
            let label = null;

            // "every N minutes"
            let m = input.match(/every (\d+) minutes?/);
            if (m) { cron = `*/${m[1]} * * * *`; label = `Every ${m[1]} minutes`; }

            // "every N hours"
            m = !cron && input.match(/every (\d+) hours?/);
            if (m) { cron = `0 */${m[1]} * * *`; label = `Every ${m[1]} hours`; }

            // "every half hour"
            m = !cron && input.match(/every half.?hour/);
            if (m) { cron = `*/30 * * * *`; label = 'Every 30 minutes'; }

            // "hourly"
            if (!cron && /\bhourly\b/.test(input)) { cron = '0 * * * *'; label = 'Every hour'; }

            // "daily at HH:MM" or "daily at HH am/pm"
            m = !cron && input.match(/daily\s+at\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/);
            if (m) {
                let h = parseInt(m[1]);
                const mins = m[2] ? parseInt(m[2]) : 0;
                if (m[3] === 'pm' && h < 12) h += 12;
                if (m[3] === 'am' && h === 12) h = 0;
                cron = `${mins} ${h} * * *`;
                label = `Daily at ${String(h).padStart(2,'0')}:${String(mins).padStart(2,'0')}`;
            }

            // "weekdays at HH am/pm"
            m = !cron && input.match(/weekdays?\s+at\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/);
            if (m) {
                let h = parseInt(m[1]);
                const mins = m[2] ? parseInt(m[2]) : 0;
                if (m[3] === 'pm' && h < 12) h += 12;
                if (m[3] === 'am' && h === 12) h = 0;
                cron = `${mins} ${h} * * 1-5`;
                label = `Weekdays at ${String(h).padStart(2,'0')}:${String(mins).padStart(2,'0')}`;
            }

            // "every [weekday] at HH am/pm"
            const days = { monday:1, tuesday:2, wednesday:3, thursday:4, friday:5, saturday:6, sunday:0 };
            m = !cron && input.match(/every\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+at\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/);
            if (m) {
                let h = parseInt(m[2]);
                const mins = m[3] ? parseInt(m[3]) : 0;
                if (m[4] === 'pm' && h < 12) h += 12;
                if (m[4] === 'am' && h === 12) h = 0;
                const dayNum = days[m[1]];
                cron = `${mins} ${h} * * ${dayNum}`;
                label = `Every ${m[1].charAt(0).toUpperCase()+m[1].slice(1)} at ${String(h).padStart(2,'0')}:${String(mins).padStart(2,'0')}`;
            }

            // "weekly" — defaults to Monday at 09:00
            if (!cron && /\bweekly\b/.test(input)) { cron = '0 9 * * 1'; label = 'Every Monday at 09:00'; }

            // "monthly" — defaults to 1st at 09:00
            if (!cron && /\bmonthly\b/.test(input)) { cron = '0 9 1 * *'; label = 'Monthly on the 1st at 09:00'; }

            if (cron) {
                this.preview = label;
                $wire.set('cronExpression', cron);
                $wire.set('frequency', 'cron');
            } else {
                this.preview = '';
            }
        }
    }
}
</script>
@endscript
