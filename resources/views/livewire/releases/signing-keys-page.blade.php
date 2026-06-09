@php
    use App\Domain\Release\Crypto\Enums\SigningKeyStatus;

    $statusStyles = [
        'active' => ['bg-green-100', 'text-green-700'],
        'grace' => ['bg-amber-100', 'text-amber-700'],
        'revoked' => ['bg-red-100', 'text-red-700'],
    ];

    // SHA-256 fingerprint of the raw Ed25519 public key, shown as a short hex
    // prefix so operators can eyeball-match keys without exposing key material.
    $fingerprint = function (string $publicKey): string {
        $raw = base64_decode($publicKey, true);
        $hash = hash('sha256', $raw !== false ? $raw : $publicKey);

        return implode(':', str_split(substr($hash, 0, 32), 4));
    };
@endphp

<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="mb-6 flex items-start justify-between gap-4">
        <p class="max-w-2xl text-sm text-gray-500">
            Ed25519 keys used to sign your team's releases. Verifiers fetch the matching public key
            from the public JWKS endpoint. Private key material is never displayed and never leaves the server.
        </p>
        @if(! $hasActive)
            <button wire:click="generate"
                class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                <i class="fa-solid fa-key mr-1"></i>Generate signing key
            </button>
        @else
            <button wire:click="rotate"
                wire:confirm="Rotate the active signing key? The current key moves to a 90-day grace period (releases signed by it still verify until it expires), and a new active key is generated."
                class="shrink-0 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fa-solid fa-rotate mr-1"></i>Rotate key
            </button>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white">
        @forelse($keys as $key)
            @php([$badgeBg, $badgeText] = $statusStyles[$key->status->value] ?? ['bg-gray-100', 'text-gray-700'])
            <div class="border-b border-gray-100 px-6 py-4 last:border-0">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center rounded-full {{ $badgeBg }} px-2 py-0.5 text-xs font-medium {{ $badgeText }}">
                                {{ $key->status->label() }}
                            </span>
                            <span class="font-mono text-xs text-gray-500" title="Key ID (kid)">{{ $key->id }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-500">
                            <span title="SHA-256 of the public key">
                                <span class="text-gray-400">Fingerprint</span>
                                <span class="font-mono text-gray-700">{{ $fingerprint($key->public_key) }}</span>
                            </span>
                            <span>
                                <span class="text-gray-400">Created</span> {{ $key->created_at?->diffForHumans() }}
                            </span>
                            @if($key->rotated_at)
                                <span>
                                    <span class="text-gray-400">Rotated</span> {{ $key->rotated_at->diffForHumans() }}
                                </span>
                            @endif
                            @if($key->status === SigningKeyStatus::Grace && $key->grace_expires_at)
                                <span>
                                    <span class="text-gray-400">Grace expires</span> {{ $key->grace_expires_at->diffForHumans() }}
                                </span>
                            @endif
                            @if($key->revoked_at)
                                <span>
                                    <span class="text-gray-400">Revoked</span> {{ $key->revoked_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if($key->status !== SigningKeyStatus::Revoked)
                        <div class="shrink-0">
                            @if($confirmingRevokeId === $key->id)
                                <div class="flex items-center gap-2">
                                    <button wire:click="revoke('{{ $key->id }}')"
                                        class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">
                                        Confirm revoke
                                    </button>
                                    <button wire:click="cancelRevoke"
                                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        Cancel
                                    </button>
                                </div>
                            @else
                                <button wire:click="confirmRevoke('{{ $key->id }}')"
                                    class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                    <i class="fa-solid fa-ban mr-1"></i>Revoke
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                @if($confirmingRevokeId === $key->id)
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        Revoking is immediate and irreversible. Every release signed by this key will
                        <strong>fail verification</strong> — there is no grace period. Use rotation instead unless this key is compromised.
                    </div>
                @endif
            </div>
        @empty
            <div class="px-6 py-12 text-center text-sm text-gray-400">
                No signing keys yet. Generate one to start signing your releases.
            </div>
        @endforelse
    </div>
</div>
