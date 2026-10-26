{{-- Cookie banner for the GA4 tag (see <x-analytics.head />). Put it early in <body>. Hidden until the
     head script finds no valid stored choice, or the "Cookie settings" link reopens it. Accept and
     Reject share one class string, so size, weight and colour are identical; nothing is pre-ticked. --}}
@if(config('services.google_analytics.id'))
    @php($choiceClass = 'min-h-11 flex-1 rounded-lg border-2 border-gray-900 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600')
    <div data-cookie-banner role="region" aria-label="Cookie notice" hidden
         class="fixed inset-x-3 bottom-3 z-[1000] mx-auto max-w-2xl rounded-xl border border-gray-900 bg-white p-4 text-sm text-gray-900 shadow-2xl">
        <p class="mb-3">
            We use Google Analytics cookies to count visits, but only if you accept.
            If you reject, we only remember your choice in your browser and set no analytics cookies.
            <a href="{{ route('legal.privacy') }}" class="underline" target="_blank" rel="noopener">Privacy policy</a>
            &middot;
            <a href="{{ route('legal.cookies') }}" class="underline" target="_blank" rel="noopener">Cookie policy</a>
        </p>
        <div class="flex gap-3">
            <button type="button" data-cookie-choice="accept" class="{{ $choiceClass }}">Accept</button>
            <button type="button" data-cookie-choice="reject" class="{{ $choiceClass }}">Reject</button>
        </div>
    </div>
@endif
