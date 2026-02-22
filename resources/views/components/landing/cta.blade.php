<section class="bg-gradient-to-r from-primary-700 via-primary-600 to-primary-700 py-16 sm:py-20">
    <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
        <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {{ $heading ?? 'Start building with Agent Fleet today' }}
        </h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">
            {{ $subtext ?? 'Free and open source. Self-host or use the cloud. No credit card required.' }}
        </p>
        <div class="mt-10 flex items-center justify-center gap-x-6">
            <a href="{{ route('register') }}"
               class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                {{ $ctaLabel ?? 'Get Started Free' }}
            </a>
            <a href="#features" class="text-sm font-semibold leading-6 text-white transition hover:text-primary-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                Learn More <span aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </div>
</section>
