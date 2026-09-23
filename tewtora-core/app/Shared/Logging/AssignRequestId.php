<?php

namespace App\Shared\Logging;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * backend-engineering-standards.md §7: generates or forwards an
 * X-Request-Id early in the pipeline, pushes it into every log line for
 * the request, and returns it on the response so support can find the
 * exact log entry for any report a parent or teacher sends in.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: 'req_'.(string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
