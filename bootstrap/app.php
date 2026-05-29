<?php

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Http\Middleware\ApplyTenantTracer;
use App\Http\Middleware\BypassAuth;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\ResolveWebsiteByDomain;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SentryContextWebMiddleware;
use App\Http\Middleware\SetCurrentTeam;
use App\Http\Middleware\SetPostgresRlsContext;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;
use League\OAuth2\Server\Exception\OAuthServerException;
use Sentry\Laravel\Integration;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::prefix('api/v1')
                ->middleware('api')
                ->name('api.v1.')
                ->group(base_path('routes/api_v1.php'));

            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/chatbot.php'));

            Route::prefix('v1')
                ->middleware('api')
                ->name('v1.')
                ->group(base_path('routes/openai.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
        $middleware->prepend(ResolveWebsiteByDomain::class);
        $middleware->appendToGroup('web', BypassAuth::class);
        $middleware->appendToGroup('web', SetCurrentTeam::class);
        $middleware->appendToGroup('web', EnsureTermsAccepted::class);
        $middleware->appendToGroup('web', SetPostgresRlsContext::class);
        $middleware->appendToGroup('web', ApplyTenantTracer::class);
        $middleware->appendToGroup('web', SentryContextWebMiddleware::class);
        $middleware->appendToGroup('api', ApplyTenantTracer::class);
        $middleware->appendToGroup('api', SentryContextWebMiddleware::class);
        $middleware->statefulApi();
        $middleware->alias([
            'scope' => CheckToken::class,
            'scopes' => CheckTokenForAnyScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // ── Sentry noise filters (mirror parent bootstrap) ─────────────────
        // OAuthServerException: legit 401 to unauthenticated MCP probers.
        // VpsLocalAgentException::concurrencyCapReached: business-as-usual
        // throttling — FallbackAiGateway routes to the next provider.
        $exceptions->dontReport([
            OAuthServerException::class,
            VpsLocalAgentException::class,
            CommandNotFoundException::class,
        ]);

        $exceptions->reportable(function (Throwable $e): ?bool {
            // Postgres connection_failure — rare container restart race.
            if ($e instanceof QueryException && str_contains($e->getMessage(), 'SQLSTATE[08006]')) {
                return false;
            }

            // Direct-IP probes (security scanners) — host is the raw IP.
            if ($e instanceof ErrorException && str_contains($e->getMessage(), 'Cannot modify header information')) {
                $host = request()?->getHttpHost();
                if ($host === null || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return false;
                }
            }

            // Anthropic auth_error / Prism 401 — fallback gateway already
            // handles by trying the next provider; not a reportable error.
            if (str_contains($e->getMessage(), 'authentication_error') && str_contains($e->getMessage(), 'x-api-key')) {
                return false;
            }

            // routes-v7.php TOCTOU during route:cache rebuild (deploy.sh retries 3x).
            if ($e instanceof ErrorException
                && str_contains($e->getMessage(), 'routes-v7.php')
                && str_contains($e->getMessage(), 'Failed to open stream')) {
                return false;
            }

            // Deprecated artisan CLI flags from autonomous agents using stale knowledge.
            if ($e instanceof \Symfony\Component\Console\Exception\RuntimeException
                && (str_contains($e->getMessage(), 'option does not exist')
                    || str_contains($e->getMessage(), 'argument does not exist'))) {
                return false;
            }

            // MaxAttemptsExceededException for RunSentryWatchdogJob — the job
            // ran over the supervisor timeout once. Unstamped signals are
            // re-picked up by the next scheduled tick (signals are status-
            // based, not time-windowed). Not actionable; was the loudest
            // single Sentry source. (FLEETQ-35 #561)
            if ($e instanceof \Illuminate\Queue\MaxAttemptsExceededException
                && str_contains($e->getMessage(), 'RunSentryWatchdogJob')) {
                return false;
            }

            return null;
        });

        // Force JSON responses for all API routes
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->is('v1/*') || $request->expectsJson();
        });

        // Consistent error envelope for API responses
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->is('v1/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof OAuthServerException => $e->getHttpStatusCode(),
                $e instanceof AuthorizationException => 403,
                $e instanceof ModelNotFoundException => 404,
                $e instanceof NotFoundHttpException => 404,
                $e instanceof MethodNotAllowedHttpException => 405,
                $e instanceof TooManyRequestsHttpException => 429,
                $e instanceof InsufficientBudgetException => 402,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $response = [
                'message' => $status === 500 && ! app()->hasDebugModeEnabled()
                    ? 'An unexpected error occurred.'
                    : $e->getMessage(),
                'error' => match ($status) {
                    401 => 'unauthenticated',
                    402 => 'insufficient_budget',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    422 => 'validation_error',
                    429 => 'too_many_requests',
                    500 => 'server_error',
                    default => 'error',
                },
            ];

            if ($e instanceof ValidationException) {
                $response['errors'] = $e->errors();
            }

            return response()->json($response, $status);
        });
    })->create();

// Flush Sentry structured logs buffer on request/job termination.
// Gated on enable_logs so it is a no-op until the feature is explicitly enabled.
$app->terminating(function () use ($app): void {
    if ($app->make('config')->get('sentry.enable_logs') && function_exists('\Sentry\logger')) {
        \Sentry\logger()->flush();
    }
});

return $app;
