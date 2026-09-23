<?php

use App\Domains\Auth\Http\Middleware\RequireAdmin;
use App\Shared\Auth\BlockActAsMutations;
use App\Shared\Exceptions\AppException;
use App\Shared\Logging\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        // Needed for Cookie::queue() (the switch-profile acting-as cookie)
        // to actually attach to API responses — not part of the 'api'
        // group by default the way it is for 'web'.
        $middleware->api(append: [
            AddQueuedCookiesToResponse::class,
            BlockActAsMutations::class,
        ]);

        $middleware->alias([
            'admin' => RequireAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // backend-engineering-standards.md §6: every API error uses the
        // same envelope, whichever domain threw it. Validation gets its
        // own shape (fields map) rather than being forced into this one.
        $exceptions->render(function (AppException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            report($e);

            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $e->statusCode());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $e->getMessage(),
                    'request_id' => $request->attributes->get('request_id'),
                    'fields' => $e->errors(),
                ],
            ], $e->status);
        });

        /**
         * Catch-all so the envelope is genuinely universal, not just for
         * AppException/ValidationException. Every abort(403)/abort(404)/
         * ->firstOrFail() in this codebase throws something in this
         * category (AuthenticationException, AuthorizationException,
         * Symfony's HttpExceptionInterface — 403/404/405/etc. all resolve
         * to this) — without this, those fell through to Laravel's raw
         * debug-mode JSON payload instead of the documented envelope, which
         * is exactly what CLAUDE.md's error-response section rules out
         * ("Anything else reaching the client as internal_error is a bug").
         * Registered after the two above so their more specific handling
         * still wins for AppException/ValidationException.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                429 => 'too_many_requests',
                default => $status >= 500 ? 'internal_error' : 'request_failed',
            };

            // Anything that isn't a routine 4xx is genuinely unexpected —
            // log it with full context rather than let it vanish behind a
            // generic message (same CLAUDE.md rule referenced above).
            if ($status >= 500) {
                report($e);
            }

            // CLAUDE.md: "message — human-readable, client-safe. Never a
            // raw exception message." ModelNotFoundException's default
            // text ("No query results for model [App\Domains\...]") leaks
            // internal class names — swap it for a generic message. Note
            // this has to check getPrevious() too: Laravel's own Handler
            // already rewraps ModelNotFoundException into
            // NotFoundHttpException (and AuthorizationException into
            // AccessDeniedHttpException) *before* this closure ever runs,
            // carrying the original message along — so `$e instanceof
            // ModelNotFoundException` alone never matches here; only
            // getPrevious() does. A plain abort(404, 'custom text') has no
            // such previous exception, so that one still passes through.
            $original = $e->getPrevious();
            $message = match (true) {
                $status >= 500 => 'Something went wrong. Support can look this up by request_id.',
                $e instanceof ModelNotFoundException,
                $e instanceof AuthorizationException,
                $original instanceof ModelNotFoundException,
                $original instanceof AuthorizationException => Response::$statusTexts[$status] ?? 'Request failed.',
                default => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Request failed.'),
            };

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $status);
        });
    })->create();
