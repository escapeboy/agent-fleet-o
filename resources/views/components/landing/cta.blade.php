@props([
    'heading'       => 'Ready to Put Your AI Agents to Work?',
    'subtext'       => 'Free and open source. MIT licensed. No cloud subscription required.',
    'ctaLabel'      => 'Get Started Free',
    'ctaHref'       => null,
    'secondaryLabel' => 'View on GitHub',
    'secondaryHref'  => 'https://github.com/escapeboy/agent-fleet-o',
])

<section id="cta" class="bg-gradient-to-r from-primary-700 via-primary-600 to-violet-700 py-20 sm:py-28">
    <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
        <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {{ $heading }}
        </h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">
            {{ $subtext }}
        </p>
        <div class="mt-10 flex items-center justify-center gap-x-6">
<<<<<<< Updated upstream
            <a href="{{ $ctaHref ?? route('register') }}"
||||||| constructed merge base
            <a href="{{ route('register') }}"
=======
            <a href="{{ auth()->check() ? route('dashboard') : route('register') }}"
>>>>>>> Stashed changes
               class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
<<<<<<< Updated upstream
                {{ $ctaLabel }}
||||||| constructed merge base
                {{ $ctaLabel ?? 'Start Free' }}
=======
                {{ auth()->check() ? 'Go to Dashboard' : ($ctaLabel ?? 'Start Free') }}
>>>>>>> Stashed changes
            </a>
            <a href="{{ $secondaryHref }}"
               class="text-sm font-semibold leading-6 text-white transition hover:text-primary-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
               @if(str_starts_with($secondaryHref, 'http')) target="_blank" rel="noopener noreferrer" @endif>
                {{ $secondaryLabel }} <span aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </div>
</section>
