<?php

namespace App\Shared\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/api-contract.md §18: an "act-as" support session "must be
 * read-only — this token/session should not be able to call any 🔒
 * mutating endpoint elsewhere in this document." Enforced centrally here
 * rather than per-route, so a new mutating endpoint added later is
 * covered automatically instead of needing every controller to
 * remember to check the token ability itself.
 */
class BlockActAsMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->can('act-as-readonly') && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort(403, 'This session is read-only.');
        }

        return $next($request);
    }
}
