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

            {{-- Signature verification badge --}}
            @php
                $badge = match($verification['status']) {
                    'verified' => ['icon' => '✓', 'color' => 'green', 'label' => 'Verified', 'desc' => 'Signature valid; signed by team\'s active key.'],
                    'verified_grace' => ['icon' => '⚠', 'color' => 'amber', 'label' => 'Verified (rotated)', 'desc' => 'Signed by a key in 90-day grace period after rotation.'],
                    'revoked' => ['icon' => '✗', 'color' => 'red', 'label' => 'Revoked', 'desc' => 'Signing key was revoked. Do not trust this release.'],
                    'unverified' => ['icon' => '✗', 'color' => 'red', 'label' => 'Unverified', 'desc' => 'Signature did not match — content may have been tampered with.'],
                    default => ['icon' => '—', 'color' => 'gray', 'label' => 'Unsigned', 'desc' => 'This release predates signing or no signing key was configured.'],
                };
            @endphp
            <div class="mt-4 inline-flex items-start gap-2 rounded-lg border border-{{ $badge['color'] }}-200 bg-{{ $badge['color'] }}-50 px-3 py-2 text-xs text-{{ $badge['color'] }}-700"
                 data-test="release-signature-badge"
                 data-test-status="{{ $verification['status'] }}">
                <span class="font-bold">{{ $badge['icon'] }}</span>
                <span>
                    <span class="font-semibold">{{ $badge['label'] }}</span> · {{ $badge['desc'] }}
                    @if($verification['kid'])
                        <br><span class="font-mono text-[10px] opacity-70">kid: {{ $verification['kid'] }}</span>
                    @endif
                </span>
            </div>
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
