<div>
@if($updateAvailable && $updateInfo)
    <div class="flex items-center justify-between gap-4 border-b border-amber-200 bg-amber-50 px-6 py-2.5 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
         role="alert">
        <div class="flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-base shrink-0 text-amber-500"></i>
            <span>
                A new version of FleetQ is available:
                <strong>v{{ $updateInfo['version'] }}</strong>
                @if($updateInfo['published_at'])
                    <span class="text-amber-700 dark:text-amber-400">
                        (released {{ \Carbon\Carbon::parse($updateInfo['published_at'])->diffForHumans() }})
                    </span>
                @endif
            </span>
            <a href="{{ route('changelog') }}"
               class="font-medium underline underline-offset-2 hover:no-underline">
                View changelog
            </a>
        </div>
        <button wire:click="dismiss"
                type="button"
                class="rounded p-0.5 text-amber-600 hover:bg-amber-100 hover:text-amber-800 dark:text-amber-400 dark:hover:bg-amber-900"
                aria-label="Dismiss update notification">
            <i class="fa-solid fa-xmark text-base"></i>
        </button>
    </div>
@endif
</div>
