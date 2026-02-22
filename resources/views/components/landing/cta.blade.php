<section id="cta" class="bg-gradient-to-r from-primary-700 via-primary-600 to-violet-700 py-20 sm:py-28">
    <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
        <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {{ $heading ?? 'Ready to Put Your AI Agents to Work?' }}
        </h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">
            {{ $subtext ?? 'Free and open source. Self-host or use the cloud. No credit card needed.' }}
        </p>
        <div class="mt-10 flex items-center justify-center gap-x-6">
            <a href="{{ route('register') }}"
               class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                {{ $ctaLabel ?? 'Start Free' }}
            </a>
            <a href="{{ url('/docs/api') }}" class="text-sm font-semibold leading-6 text-white transition hover:text-primary-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                Read the Docs <span aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </div>
</section>
