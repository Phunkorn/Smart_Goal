<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isPasswordSetupRoute = $request->routeIs('password.setup', 'password.update.first');

        if (! $user->must_change_password) {
            if (! $isPasswordSetupRoute) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                abort(403, 'Your password has already been configured.');
            }

            return redirect()->route($user->role === 'user' ? 'mytasks.index' : 'board.index');
        }

        if ($request->routeIs('password.setup', 'password.update.first', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'You must change your password before continuing.');
        }

        return redirect()->route('password.setup');
    }
}
