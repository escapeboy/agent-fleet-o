<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $release->name }} {{ $release->version }} — {{ config('app.name') }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    <div class="mx-auto max-w-3xl px-4 py-12">
        <header class="mb-8">
            <div class="text-xs uppercase tracking-wider text-gray-500">Release</div>
            <h1 class="mt-1 text-3xl font-bold">{{ $release->name }}</h1>
            <div class="mt-2 flex items-center gap-3 text-sm text-gray-600">
                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs">{{ $release->version }}</span>
                <span>Published {{ $release->published_at?->toFormattedDateString() }}</span>
            </div>
            @if($release->notes)
                <p class="mt-4 text-base text-gray-700">{{ $release->notes }}</p>
            @endif
        </header>

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-500">
                Artifacts ({{ $artifacts->count() }})
            </h2>
            <div class="rounded-xl border border-gray-200 bg-white">
                @forelse($artifacts as $artifact)
                    <div class="border-b border-gray-100 px-6 py-4 last:border-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-medium">{{ $artifact->name }}</div>
                                <div class="text-xs text-gray-500">{{ $artifact->type }} · v{{ $artifact->pivot->artifact_version }}</div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gray-400">No artifacts in this release.</div>
                @endforelse
            </div>
        </section>

        <footer class="mt-10 text-center text-xs text-gray-400">
            Powered by <span class="font-medium">{{ config('app.name') }}</span>
        </footer>
    </div>
</body>
</html>
