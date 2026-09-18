<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Guards every /api/demo/* route: the demo operator controls are available only
 * to Ops users and only outside production. In production (config app.env) the
 * whole surface returns 403, and a non-ops user is refused as well. Reads the
 * environment via config(), never env(), so it respects config caching.
 */
class EnsureDemoAccess
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (config('app.env') === 'production') {
            abort(Response::HTTP_FORBIDDEN, 'Demo controls are disabled in production.');
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->isOps()) {
            abort(Response::HTTP_FORBIDDEN, 'Demo controls are available to Ops only.');
        }

        return $next($request);
    }
}
