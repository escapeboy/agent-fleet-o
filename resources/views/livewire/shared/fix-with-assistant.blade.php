@php
    $authUser = auth()->user();
    $tier = $authUser && $authUser->currentTeam
        ? $authUser->teamRole($authUser->currentTeam)
        : null;
    $canEdit = $tier ? $tier->canEdit() : true;  // base = single team, default allow
    $canManage = $tier ? $tier->canManageTeam() : true;
@endphp

<div>
    @if($eligible)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-start gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                    <i class="fa-solid fa-stethoscope text-base"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold text-amber-900">
                            @if($diagnosis)
                                {{ __('Diagnosis') }}
                            @else
                                {{ __('Something broke. Want to know why?') }}
                            @endif
                        </h3>

                        @unless($diagnosis)
                            <button
                                wire:click="diagnose"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="diagnose">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                    {{ __('Diagnose') }}
                                </span>
                                <span wire:loading wire:target="diagnose">
                                    <i class="fa-solid fa-spinner fa-spin"></i>
                                    {{ __('Diagnosing...') }}
                                </span>
                            </button>
                        @endunless
                    </div>

                    @if($errorMessage !== '')
                        <p class="mt-2 text-sm text-red-700">{{ $errorMessage }}</p>
                    @endif

                    @if($diagnosis)
                        <p class="mt-1 text-sm text-amber-900">
                            {{ $diagnosis['summary'] ?? '' }}
                        </p>

                        @if(!empty($diagnosis['recommended_actions']))
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach($diagnosis['recommended_actions'] as $action)
                                    @php
                                        $tierGate = $action['tier'] ?? 'safe';
                                        $allowed = match($tierGate) {
                                            'destructive' => $canManage,
                                            'config'      => $canEdit,
                                            default       => true,
                                        };
                                    @endphp

                                    @if(!$allowed)
                                        @continue
                                    @endif

                                    @if(($action['kind'] ?? '') === 'route')
                                        @php
                                            $href = '#';
                                            $name = (string) ($action['target'] ?? '');
                                            try {
                                                $href = $name !== '' ? route($name, $action['params'] ?? []) : '#';
                                            } catch (\Throwable $e) {
                                                $href = '#';
                                            }
                                        @endphp
                                        <a
                                            href="{{ $href }}"
                                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100"
                                        >
                                            @if(!empty($action['icon']))
                                                <i class="fa-solid {{ $action['icon'] }}"></i>
                                            @endif
                                            {{ $action['label'] ?? '' }}
                                        </a>

                                    @elseif(($action['kind'] ?? '') === 'assistant')
                                        <button
                                            wire:click="askAssistant(@js($action['target'] ?? ''))"
                                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100"
                                        >
                                            @if(!empty($action['icon']))
                                                <i class="fa-solid {{ $action['icon'] }}"></i>
                                            @endif
                                            {{ $action['label'] ?? '' }}
                                        </button>

                                    @elseif(($action['kind'] ?? '') === 'tool' && $tierGate === 'safe')
                                        {{-- P1 inline recovery: safe-tier tool actions execute directly,
                                             bypassing the assistant for one-click retry. The Livewire method
                                             enforces an explicit allowlist of tools that may run this way. --}}
                                        <button
                                            wire:click="executeRecoveryAction(@js($action['target'] ?? ''), @js($action['params'] ?? []))"
                                            wire:loading.attr="disabled"
                                            wire:target="executeRecoveryAction"
                                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="executeRecoveryAction">
                                                @if(!empty($action['icon']))
                                                    <i class="fa-solid {{ $action['icon'] }}"></i>
                                                @endif
                                                {{ $action['label'] ?? '' }}
                                            </span>
                                            <span wire:loading wire:target="executeRecoveryAction">
                                                <i class="fa-solid fa-spinner fa-spin"></i>
                                                {{ __('Running...') }}
                                            </span>
                                        </button>

                                    @elseif(($action['kind'] ?? '') === 'tool')
                                        {{-- config / destructive tool actions still funnel into the assistant
                                             so the existing role-tier ladder gates execution. --}}
                                        @php
                                            $toolPrompt = sprintf(
                                                'Please call the %s MCP tool with these arguments: %s',
                                                (string) ($action['target'] ?? ''),
                                                json_encode($action['params'] ?? [], JSON_UNESCAPED_SLASHES),
                                            );
                                        @endphp
                                        <button
                                            wire:click="askAssistant(@js($toolPrompt))"
                                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100"
                                        >
                                            @if(!empty($action['icon']))
                                                <i class="fa-solid {{ $action['icon'] }}"></i>
                                            @endif
                                            {{ $action['label'] ?? '' }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @if(!empty($diagnosis['confidence']))
                            <p class="mt-2 text-xs text-amber-700">
                                {{ __('Confidence') }}: {{ (int) round(((float) $diagnosis['confidence']) * 100) }}%
                                @if(!empty($diagnosis['root_cause']))
                                    · {{ $diagnosis['root_cause'] }}
                                @endif
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
