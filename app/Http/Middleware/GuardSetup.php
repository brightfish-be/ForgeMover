<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * /setup is open on a fresh install; once an admin user exists it is only
 * reachable for logged-in users (prevents post-install takeover).
 */
class GuardSetup
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $hasUsers = User::count() > 0;
        } catch (\Throwable) {
            $hasUsers = false; // database not migrated yet — setup must stay reachable
        }

        if ($hasUsers && ! $request->user()) {
            return redirect('/admin/login');
        }

        return $next($request);
    }
}
