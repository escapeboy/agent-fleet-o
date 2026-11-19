<section data-docs-next-step data-cta-location="docs_next_step"
         class="mt-16 border-t border-gray-200 pt-10">
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-6">
        <h2 class="text-lg font-semibold text-gray-900">Next step</h2>
        <p class="mt-2 text-sm text-gray-600">Start from a ready-made agent template, then run it on FleetQ Cloud or your own server.</p>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
            @if(Route::has('templates.index'))
                <a href="{{ route('templates.index') }}"
                   data-analytics-event="cta_click" data-analytics-label="browse_templates"
                   class="rounded-lg bg-primary-600 px-4 py-2 text-center text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    Browse agent templates
                </a>
            @endif
            @if(Route::has('register') && ! auth()->check())
                <a href="{{ route('register') }}"
                   data-analytics-event="cta_click" data-analytics-label="sign_up"
                   class="rounded-lg border border-primary-600 bg-white px-4 py-2 text-center text-sm font-semibold text-primary-600 transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    Create a free account
                </a>
            @endif
            <a href="https://github.com/escapeboy/agent-fleet-o"
               target="_blank" rel="noopener noreferrer"
               data-analytics-event="cta_click" data-analytics-label="self_host_github"
               class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-center text-sm font-semibold text-gray-700 transition hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                Self-host from GitHub
            </a>
        </div>
    </div>
</section>
