<?php

use App\Shared\Auth\TrustGatewayIdentity;
use App\Shared\Exceptions\AppException;
use App\Shared\Logging\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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

        $middleware->alias([
            'gateway.identity' => TrustGatewayIdentity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // backend-engineering-standards.md §6: same envelope as tewtora-core.
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

        // Catch-all — see tewtora-core's bootstrap/app.php for the full
        // rationale. Same envelope contract applies to both services.
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

            if ($status >= 500) {
                report($e);
            }

            // See tewtora-core's bootstrap/app.php: ModelNotFoundException/
            // AuthorizationException leak internal class names in their
            // default message, which CLAUDE.md's "never a raw exception
            // message" rule rules out. Laravel's own Handler already
            // rewraps these into NotFoundHttpException/
            // AccessDeniedHttpException before this closure runs, carrying
            // the original message along — checking getPrevious() too is
            // what actually catches it.
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
