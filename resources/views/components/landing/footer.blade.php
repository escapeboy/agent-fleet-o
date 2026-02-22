<footer class="bg-gray-900 text-gray-400">
    {{-- Supported Providers strip --}}
    <div class="border-b border-gray-800">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-8 gap-y-4 px-6 py-8 lg:px-8">
            <span class="text-xs font-medium uppercase tracking-wider text-gray-500">Powered by</span>
            <div class="flex items-center gap-8 text-gray-500">
                {{-- Anthropic --}}
                <div class="flex items-center gap-1.5 text-sm font-medium transition hover:text-gray-300" title="Anthropic Claude">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M13.827 3.52l3.904 16.56H22L17.791 3.52h-3.964zm-9.596 0L0 20.08h4.248l.837-3.524h4.584l.836 3.524h4.248L10.523 3.52H4.231zm3.14 5.322l1.6 6.95H5.772l1.599-6.95z"/></svg>
                    <span>Anthropic</span>
                </div>
                {{-- OpenAI --}}
                <div class="flex items-center gap-1.5 text-sm font-medium transition hover:text-gray-300" title="OpenAI GPT-4o">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.998 5.998 0 0 0-3.998 2.9 6.047 6.047 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.612-1.5z"/></svg>
                    <span>OpenAI</span>
                </div>
                {{-- Google --}}
                <div class="flex items-center gap-1.5 text-sm font-medium transition hover:text-gray-300" title="Google Gemini">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.372 0 0 5.373 0 12s5.372 12 12 12 12-5.373 12-12S18.628 0 12 0zm0 2.824a9.176 9.176 0 1 1 0 18.352 9.176 9.176 0 0 1 0-18.352z"/></svg>
                    <span>Google</span>
                </div>
                {{-- Laravel --}}
                <div class="flex items-center gap-1.5 text-sm font-medium transition hover:text-gray-300" title="Built with Laravel">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M23.642 5.43a.364.364 0 01.014.1v5.149c0 .135-.073.26-.189.326l-4.323 2.49v4.934a.378.378 0 01-.188.326L9.93 23.949a.316.316 0 01-.066.027c-.008.002-.016.008-.024.01a.348.348 0 01-.192 0c-.011-.002-.02-.008-.03-.012a.283.283 0 01-.06-.023L.533 18.755a.376.376 0 01-.189-.326V2.974c0-.033.005-.066.014-.098.003-.012.01-.02.014-.032a.369.369 0 01.023-.058c.004-.013.015-.022.023-.033l.033-.045c.012-.01.025-.018.037-.027.014-.009.024-.02.038-.027L4.89.086a.377.377 0 01.377 0l4.365 2.518c.013.007.024.018.037.027.013.009.024.017.036.027l.028.042.028.036c.009.018.013.035.019.054.007.012.013.024.015.035.009.032.014.065.014.098v9.652l3.76-2.164V5.527c0-.033.004-.066.013-.098.003-.011.01-.021.013-.032.01-.02.016-.038.024-.058.008-.011.015-.022.023-.033.01-.015.021-.03.033-.043.012-.012.025-.02.037-.028.013-.009.024-.02.037-.028l4.366-2.516a.377.377 0 01.376 0l4.366 2.516c.015.008.024.02.038.028.013.008.026.016.036.028.016.014.024.028.034.043l.024.033c.008.02.016.038.023.058.006.011.012.021.015.032zm-.742 5.032V6.179l-1.578.908-2.182 1.256v4.283zm-4.365 7.493v-4.29l-2.145 1.225-6.586 3.762v4.336zM1.093 3.624v14.588l8.273 4.761v-4.337l-4.322-2.445-.002-.003-.002-.002c-.014-.01-.025-.021-.036-.03-.013-.01-.024-.017-.035-.027l-.001-.002c-.013-.012-.021-.025-.031-.04-.01-.011-.021-.022-.028-.036v-.002c-.011-.015-.017-.03-.025-.047-.006-.013-.015-.024-.018-.038-.012-.036-.017-.074-.017-.112V6.089L2.67 4.945z"/></svg>
                    <span>Laravel</span>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
        <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
            {{-- Product --}}
            <div>
                <p class="text-sm font-semibold text-white">Product</p>
                <ul class="mt-4 space-y-3">
                    <li><a href="#features" class="text-sm transition hover:text-white">Features</a></li>
                    {{ $productLinks ?? '' }}
                    <li><a href="{{ route('marketplace.index') }}" class="text-sm transition hover:text-white">Marketplace</a></li>
                    <li><a href="#how-it-works" class="text-sm transition hover:text-white">How It Works</a></li>
                </ul>
            </div>

            {{-- Resources --}}
            <div>
                <p class="text-sm font-semibold text-white">Resources</p>
                <ul class="mt-4 space-y-3">
                    {{ $resourceLinks ?? '' }}
                    <li><a href="{{ url('/docs/api') }}" class="text-sm transition hover:text-white">API Docs</a></li>
                    <li><a href="https://github.com/escapeboy/agent-fleet-o/blob/main/CHANGELOG.md" class="text-sm transition hover:text-white" rel="noopener noreferrer" target="_blank">Changelog</a></li>
                    <li><a href="#faq" class="text-sm transition hover:text-white">FAQ</a></li>
                </ul>
            </div>

            {{-- Community --}}
            <div>
                <p class="text-sm font-semibold text-white">Community</p>
                <ul class="mt-4 space-y-3">
                    {{ $communityLinks ?? '' }}
                    <li><a href="https://github.com/escapeboy/agent-fleet-o" class="text-sm transition hover:text-white" rel="noopener noreferrer" target="_blank">GitHub</a></li>
                    <li><a href="https://github.com/escapeboy/agent-fleet-o/issues" class="text-sm transition hover:text-white" rel="noopener noreferrer" target="_blank">Issues</a></li>
                    <li><a href="https://github.com/escapeboy/agent-fleet-o/blob/main/CONTRIBUTING.md" class="text-sm transition hover:text-white" rel="noopener noreferrer" target="_blank">Contributing</a></li>
                </ul>
            </div>

            {{-- Legal --}}
            <div>
                <p class="text-sm font-semibold text-white">Legal</p>
                <ul class="mt-4 space-y-3">
                    <li><a href="{{ route('legal.privacy') }}" class="text-sm transition hover:text-white">Privacy Policy</a></li>
                    <li><a href="{{ route('legal.cookies') }}" class="text-sm transition hover:text-white">Cookie Policy</a></li>
                    <li><a href="{{ route('legal.terms') }}" class="text-sm transition hover:text-white">Terms of Service</a></li>
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
                <div class="flex items-center gap-4">
                    <a href="https://github.com/escapeboy/agent-fleet-o" class="text-gray-400 transition hover:text-white" rel="noopener noreferrer" target="_blank" aria-label="GitHub">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/></svg>
                    </a>
                    <p class="text-sm">&copy; {{ date('Y') }} Agent Fleet. MIT License.</p>
                </div>
            </div>
        </div>
    </div>
</footer>
