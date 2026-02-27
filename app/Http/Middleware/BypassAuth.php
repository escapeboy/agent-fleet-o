<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Services\DeploymentMode;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-login as the first user when APP_AUTH_BYPASS=true.
 *
 * Only activates when ALL three conditions are met:
 *   1. APP_AUTH_BYPASS=true in .env
 *   2. APP_ENV is not 'production'
 *   3. DEPLOYMENT_MODE=self-hosted
 *
 * Designed for local single-user installs where passwords are unnecessary.
 * NEVER enable this on a publicly accessible server.
 */
class BypassAuth
{
    public function __construct(private readonly DeploymentMode $deploymentMode) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('app.auth_bypass') === true
            && config('app.env') !== 'production'
            && $this->deploymentMode->isSelfHosted()
            && ! Auth::check()
        ) {
            $user = User::first();

            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
