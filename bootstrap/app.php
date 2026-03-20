<?php

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Http\Middleware\BypassAuth;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentTeam;
use App\Http\Middleware\SetPostgresRlsContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
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
        $middleware->appendToGroup('web', BypassAuth::class);
        $middleware->appendToGroup('web', SetCurrentTeam::class);
        $middleware->appendToGroup('web', EnsureTermsAccepted::class);
        $middleware->appendToGroup('web', SetPostgresRlsContext::class);
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

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
