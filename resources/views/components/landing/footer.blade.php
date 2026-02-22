<footer class="bg-gray-900 text-gray-400">
    <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
        <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
            {{-- Product --}}
            <div>
                <h4 class="text-sm font-semibold text-white">Product</h4>
                <ul class="mt-4 space-y-3">
                    <li><a href="#features" class="text-sm transition hover:text-white">Features</a></li>
                    {{ $productLinks ?? '' }}
                    <li><a href="{{ route('marketplace.index') }}" class="text-sm transition hover:text-white">Marketplace</a></li>
                    <li><a href="#how-it-works" class="text-sm transition hover:text-white">How It Works</a></li>
                </ul>
            </div>

            {{-- Resources --}}
            <div>
                <h4 class="text-sm font-semibold text-white">Resources</h4>
                <ul class="mt-4 space-y-3">
                    {{ $resourceLinks ?? '' }}
                    <li><a href="{{ url('/docs/api') }}" class="text-sm transition hover:text-white">API Docs</a></li>
                    <li><a href="#faq" class="text-sm transition hover:text-white">FAQ</a></li>
                </ul>
            </div>

            {{-- Community --}}
            <div>
                <h4 class="text-sm font-semibold text-white">Community</h4>
                <ul class="mt-4 space-y-3">
                    {{ $communityLinks ?? '' }}
                    <li><a href="https://github.com/agent-fleet/agent-fleet" class="text-sm transition hover:text-white" rel="noopener noreferrer" target="_blank">GitHub</a></li>
                </ul>
            </div>

            {{-- Legal --}}
            <div>
                <h4 class="text-sm font-semibold text-white">Legal</h4>
                <ul class="mt-4 space-y-3">
                    {{ $legalLinks ?? '' }}
                    <li><span class="text-sm text-gray-500">MIT License</span></li>
                </ul>
            </div>
        </div>

        <div class="mt-12 border-t border-gray-800 pt-8">
            <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-md bg-primary-600">
                        <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </div>
                    <span class="text-sm font-semibold text-white">Agent Fleet</span>
                </div>
                <p class="text-sm">&copy; {{ date('Y') }} Agent Fleet. All rights reserved.</p>
            </div>
        </div>
    </div>
</footer>
