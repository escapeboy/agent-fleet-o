<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TermsAcceptanceService
{
    /**
     * Record the user's acceptance of the current terms version.
     * Writes atomically to both the users table and the immutable audit log.
     */
    public function record(User $user, Request $request, string $method): void
    {
        $version = config('terms.current_version');

        DB::transaction(function () use ($user, $version, $request, $method) {
            $user->update([
                'terms_version' => $version,
                'terms_accepted_at' => now(),
            ]);

            TermsAcceptance::create([
                'user_id' => $user->id,
                'version' => $version,
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500),
                'acceptance_method' => $method,
            ]);
        });
    }

    /**
     * Returns true if the user must accept a newer version of the terms.
     * Always returns false when current_version is 0 (enforcement disabled).
     */
    public function requiresAcceptance(User $user): bool
    {
        $current = config('terms.current_version');

        return $current > 0 && ($user->terms_version === null || $user->terms_version < $current);
    }
}
