<?php

namespace App\Http\Controllers;

use App\Domain\Integration\Actions\OAuthCallbackAction;
use App\Domain\Integration\Actions\OAuthConnectAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationOAuthController extends Controller
{
    /**
     * Redirect the user to the OAuth2 provider's authorization page.
     */
    public function redirect(Request $request, string $driver): RedirectResponse
    {
        // Fix 3: reject unknown drivers early
        if (! array_key_exists($driver, config('integrations.oauth', []))) {
            return redirect()->route('integrations.index')
                ->with('error', 'Unknown integration driver.');
        }

        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam;

        if (! $team) {
            return redirect()->route('integrations.index')
                ->with('error', 'No team context. Please complete setup first.');
        }

        $name = (string) ($request->query('name') ?: ucfirst($driver).' Integration');

        try {
            $action = app(OAuthConnectAction::class);
            $url = $action->execute(
                teamId: $team->getKey(),
                driver: $driver,
                name: $name,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('integrations.index')
                ->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    /**
     * Handle the OAuth2 provider callback and complete the connection.
     */
    public function callback(Request $request, string $driver): RedirectResponse
    {
        // Fix 3: reject unknown drivers early
        if (! array_key_exists($driver, config('integrations.oauth', []))) {
            return redirect()->route('integrations.index')
                ->with('error', 'Unknown integration driver.');
        }

        $error = $request->query('error');

        if ($error) {
            // Fix 4: sanitize provider-supplied error description before reflecting it
            $description = strip_tags(mb_substr((string) ($request->query('error_description') ?? $error), 0, 200));

            return redirect()->route('integrations.index')
                ->with('error', "Authorization denied: {$description}");
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect()->route('integrations.index')
                ->with('error', 'Missing authorization code or state parameter.');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $action = app(OAuthCallbackAction::class);
            $integration = $action->execute(
                driver: $driver,
                code: (string) $code,
                state: (string) $state,
                // Fix 1: bind state token to the authenticated user's team
                authenticatedTeamId: $user->currentTeam?->getKey(),
            );

            return redirect()->route('integrations.show', $integration)
                ->with('message', ucfirst($driver).' connected successfully.');
        } catch (\Throwable $e) {
            // Fix 4: sanitize exception messages that may contain provider-supplied strings
            return redirect()->route('integrations.index')
                ->with('error', 'Connection failed: '.strip_tags(mb_substr($e->getMessage(), 0, 300)));
        }
    }
}
