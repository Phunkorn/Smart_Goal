<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guard ทั่วไปสำหรับจำกัด role ที่เข้าถึง route ได้ ใช้คู่กับ middleware alias 'role'
 * เช่น ->middleware('role:admin,user') อนุญาตเฉพาะ role ที่ระบุ (comma-separated)
 * ต่างจาก AdminOnly ที่ล็อกไว้เฉพาะ admin เพียง role เดียวเท่านั้น
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! Auth::check() || ! in_array(Auth::user()->role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
