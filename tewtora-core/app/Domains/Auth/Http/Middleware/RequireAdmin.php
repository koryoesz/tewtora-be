<?php

namespace App\Domains\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * backend-engineering-standards.md §4: "Staff/admin bypass goes through a
 * dedicated Gate... never by omitting the scope." A hard account-type gate
 * in addition to whatever policy each admin controller still applies.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->account_type === 'admin' || abort(403);

        return $next($request);
    }
}
