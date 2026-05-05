@props(['feature', 'requiredPlan' => 'Pro', 'mode' => 'inline', 'upgradeMessage' => null])

@php
    $team = auth()->user()?->currentTeam;
    $locked = $team && !$team->hasFeature($feature);
    $canManageBilling = $locked && Route::has('billing') && Gate::allows('manage-billing');
    $message = $upgradeMessage ?? "Available on {$requiredPlan} and above.";
@endphp

@if(!$locked)
    {{ $slot }}
@elseif($mode === 'overlay')
    <div class="relative">
        <div class="pointer-events-none select-none blur-sm opacity-60">
            {{ $slot }}
        </div>
        <div class="absolute inset-0 flex items-center justify-center rounded-lg bg-white/60 backdrop-blur-[2px]">
            <div class="max-w-xs rounded-xl border border-gray-200 bg-white p-5 text-center shadow-lg">
                <span class="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-violet-500 to-purple-600 px-2.5 py-0.5 text-xs font-bold text-white">
                    {{ $requiredPlan }}
                </span>
                <p class="mt-2 text-sm font-medium text-gray-900">{{ $message }}</p>
                @if($canManageBilling)
                    <a href="{{ route('billing') }}" class="mt-3 inline-flex items-center rounded-md bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700">
                        Upgrade to {{ $requiredPlan }}
                    </a>
                @elseif(Route::has('billing'))
                    <p class="mt-2 text-xs text-gray-500">Contact your workspace owner to upgrade.</p>
                @endif
            </div>
        </div>
    </div>
@elseif($mode === 'ghost')
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50/50 p-8 text-center">
        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-gray-100">
            <i class="fa-solid fa-lock text-base text-gray-400"></i>
        </div>
        <p class="text-sm font-medium text-gray-600">{{ $message }}</p>
        @if($canManageBilling)
            <a href="{{ route('billing') }}" class="mt-1 text-xs font-semibold text-primary-600 hover:underline">
                Upgrade to {{ $requiredPlan }} &rarr;
            </a>
        @elseif(Route::has('billing'))
            <p class="mt-1 text-xs text-gray-500">Contact your workspace owner to upgrade.</p>
        @endif
    </div>
@else
    {{-- inline mode (default) --}}
    <div class="relative">
        <div class="pointer-events-none select-none opacity-60">
            {{ $slot }}
        </div>
        <div class="mt-2 flex items-center gap-2">
            <span class="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-violet-500 to-purple-600 px-2 py-0.5 text-xs font-bold text-white">
                {{ $requiredPlan }}
            </span>
            @if($canManageBilling)
                <a href="{{ route('billing') }}" class="text-xs font-semibold text-primary-600 hover:underline">
                    Upgrade to {{ $requiredPlan }} &rarr;
                </a>
            @elseif(Route::has('billing'))
                <p class="text-xs text-gray-500">Contact your workspace owner to upgrade.</p>
            @endif
        </div>
    </div>
@endif
