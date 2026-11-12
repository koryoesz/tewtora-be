<?php

namespace App\Shared\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /internal/* (service-to-service calls from tewtora-core) with the
 * shared INTERNAL_SERVICE_TOKEN, compared in constant time. With no token
 * configured every call is refused: an unset secret must fail closed.
 */
class VerifyInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal.token');
        $given = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            abort(401, 'Invalid internal service token.');
        }

        return $next($request);
    }
}
