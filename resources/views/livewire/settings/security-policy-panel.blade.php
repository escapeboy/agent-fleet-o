<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-base font-semibold text-(--color-on-surface)">Organization Security Policy</h3>
            <p class="text-sm text-(--color-on-surface-muted)">Command restrictions applied to all agents across all projects.</p>
        </div>
        @if(!$editing)
            <button wire:click="$set('editing', true)" class="rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                Edit Policy
            </button>
        @endif
    </div>

    @if(session()->has('security-saved'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('security-saved') }}
        </div>
    @endif

    {{-- Hierarchy diagram --}}
    <div class="rounded-lg bg-(--color-surface-alt) border border-(--color-theme-border) p-3">
        <p class="mb-2 text-xs font-medium text-(--color-on-surface-muted)">Security Hierarchy (most restrictive wins)</p>
        <div class="flex items-center gap-2 text-xs">
            <span class="rounded bg-red-100 px-2 py-0.5 font-medium text-red-700">Platform</span>
            <span class="text-(--color-on-surface-muted)">&rarr;</span>
            <span class="rounded bg-orange-100 px-2 py-0.5 font-medium text-orange-700">Organization</span>
            <span class="text-(--color-on-surface-muted)">&rarr;</span>
            <span class="rounded bg-blue-100 px-2 py-0.5 font-medium text-blue-700">Tool</span>
            <span class="text-(--color-on-surface-muted)">&rarr;</span>
            <span class="rounded bg-purple-100 px-2 py-0.5 font-medium text-purple-700">Project</span>
            <span class="text-(--color-on-surface-muted)">&rarr;</span>
            <span class="rounded bg-(--color-surface-alt) px-2 py-0.5 font-medium text-(--color-on-surface)">Agent</span>
        </div>
    </div>

    @if($editing)
        <div class="space-y-4 rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) p-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                {{-- Blocked Commands --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Blocked Commands</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">One command per line. These binaries will be blocked.</p>
                    <textarea wire:model="blockedCommands" rows="4" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) font-mono text-sm" placeholder="docker&#10;kubectl&#10;nmap"></textarea>
                </div>

                {{-- Blocked Patterns --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Blocked Patterns</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">Substrings blocked in any command.</p>
                    <textarea wire:model="blockedPatterns" rows="4" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) font-mono text-sm" placeholder="--privileged&#10;--rm -f&#10;--network=host"></textarea>
                </div>

                {{-- Allowed Commands --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Allowed Commands (Whitelist)</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">If set, only these commands are allowed. Leave empty for no restriction.</p>
                    <textarea wire:model="allowedCommands" rows="4" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) font-mono text-sm" placeholder="curl&#10;jq&#10;python3&#10;node"></textarea>
                </div>

                {{-- Allowed Paths --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Allowed Paths</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">Working directories allowed for agent commands.</p>
                    <textarea wire:model="allowedPaths" rows="4" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) font-mono text-sm" placeholder="/tmp/agent-workspace&#10;/workspace"></textarea>
                </div>

                {{-- Require Approval For --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Require Approval For</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">Commands matching these patterns will require human approval.</p>
                    <textarea wire:model="requireApprovalFor" rows="4" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) font-mono text-sm" placeholder="pip install&#10;npm install&#10;apt-get"></textarea>
                </div>

                {{-- Max Timeout --}}
                <div>
                    <label class="block text-sm font-medium text-(--color-on-surface)">Max Command Timeout (seconds)</label>
                    <p class="mb-1 text-xs text-(--color-on-surface-muted)">Maximum execution time for any single command.</p>
                    <input type="number" wire:model="maxCommandTimeout" class="w-full rounded-lg border-(--color-input-border) bg-(--color-input-bg) text-(--color-on-surface) text-sm" placeholder="300" min="0" max="3600">
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-(--color-theme-border) pt-4">
                <button wire:click="resetPolicy" wire:confirm="Reset organization security policy to defaults?"
                    class="text-sm text-red-600 hover:text-red-800">
                    Reset to Default
                </button>
                <div class="flex items-center gap-2">
                    <button wire:click="$set('editing', false)" class="rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                        Cancel
                    </button>
                    <button wire:click="save" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Save Policy
                    </button>
                </div>
            </div>
        </div>
    @else
        {{-- Read-only view --}}
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            @php
                $policy = \App\Models\GlobalSetting::get('org_security_policy', []);
                $blocked = $policy['blocked_commands'] ?? [];
                $patterns = $policy['blocked_patterns'] ?? [];
                $allowed = $policy['allowed_commands'] ?? [];
            @endphp

            <div class="rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) p-3">
                <p class="text-xs font-medium text-(--color-on-surface-muted)">Blocked Commands</p>
                @if(empty($blocked))
                    <p class="mt-1 text-sm text-(--color-on-surface-muted)">None</p>
                @else
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach($blocked as $cmd)
                            <span class="inline-flex rounded bg-red-100 px-1.5 py-0.5 text-xs font-mono text-red-700">{{ $cmd }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) p-3">
                <p class="text-xs font-medium text-(--color-on-surface-muted)">Blocked Patterns</p>
                @if(empty($patterns))
                    <p class="mt-1 text-sm text-(--color-on-surface-muted)">None</p>
                @else
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach($patterns as $pat)
                            <span class="inline-flex rounded bg-orange-100 px-1.5 py-0.5 text-xs font-mono text-orange-700">{{ $pat }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) p-3">
                <p class="text-xs font-medium text-(--color-on-surface-muted)">Allowed Commands</p>
                @if(empty($allowed))
                    <p class="mt-1 text-sm text-(--color-on-surface-muted)">Unrestricted</p>
                @else
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach($allowed as $cmd)
                            <span class="inline-flex rounded bg-green-100 px-1.5 py-0.5 text-xs font-mono text-green-700">{{ $cmd }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
