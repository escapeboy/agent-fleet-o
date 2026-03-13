<div>
@if($updateAvailable && $updateInfo)
    <div class="flex items-center justify-between gap-4 border-b border-amber-200 bg-amber-50 px-6 py-2.5 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
         role="alert">
        <div class="flex items-center gap-2">
            <svg class="h-4 w-4 shrink-0 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
            </svg>
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
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
            </svg>
        </button>
    </div>
@endif
</div>
