{{-- Platform features the team can switch on or off for itself (config/team_features.php). --}}
@php($canManageTeam = auth()->user()?->can('manage-team', auth()->user()?->currentTeam))
<div class="rounded-lg border border-gray-200 bg-white p-6 max-w-3xl">
    <h2 class="mb-1 text-lg font-semibold text-gray-900">Platform Features</h2>
    <p class="mb-4 text-sm text-gray-500">Turn platform features on or off for this team. A feature the platform has switched off cannot be turned on here.</p>

    <div class="divide-y divide-gray-100">
        @foreach(collect($this->teamFeatureRows)->groupBy('group') as $group => $rows)
            <div class="py-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $group }}</p>
                <div class="space-y-3">
                    @foreach($rows as $row)
                        <div class="flex items-start justify-between gap-4" wire:key="team-feature-{{ $row['key'] }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800">{{ $row['label'] }}</p>
                                <p class="text-xs text-gray-500">{{ $row['description'] }}</p>
                                @unless($row['platform_enabled'])
                                    <p class="mt-1 text-xs text-amber-600">Turned off for the whole platform.</p>
                                @endunless
                            </div>
                            <button type="button"
                                role="switch"
                                aria-checked="{{ $row['enabled'] ? 'true' : 'false' }}"
                                aria-label="{{ $row['label'] }}"
                                wire:click="toggleTeamFeature('{{ $row['key'] }}')"
                                @disabled(! $row['platform_enabled'] || ! $canManageTeam)
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50
                                    {{ $row['enabled'] ? 'bg-primary-600' : 'bg-gray-300' }}">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform
                                    {{ $row['enabled'] ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
