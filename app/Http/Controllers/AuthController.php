<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            $user = Auth::user();

            if ($user->must_change_password) {
                return redirect()->route('password.setup');
            }

            return redirect()->route($user->role === 'user' ? 'mytasks.index' : 'board.index');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->merge([
            'username' => User::normalizeUsername($request->input('username')),
        ]);

        $credentials = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-z0-9._-]+\z/'],
            'password' => ['required'],
        ]);

        $this->ensureLoginIsNotRateLimited($request);
        $throttleKey = $this->throttleKey($request);

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withErrors([
                    'username' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง',
                ])
                ->onlyInput('username');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();

        if ($user->must_change_password) {
            return redirect()->route('password.setup');
        }

        if ($user->role === 'admin') {
            return redirect()->route('board.index');
        }

        if ($user->role === 'viewer') {
            return redirect()->route('board.index');
        }

        if ($user->role === 'user') {
            return redirect()->route('mytasks.index');
        }

        $this->logout($request);

        return redirect()
            ->route('login')
            ->withErrors([
                'username' => 'บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
            ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function welcome()
    {
        auth()->user()->load('department');

        return view('auth.welcome');
    }

    public function updateFirstPassword(Request $request)
    {
        $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ], [
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.confirmed' => 'รหัสผ่านทั้งสองช่องไม่ตรงกัน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
        ]);

        $user = $request->user();

        $user->password = Hash::make($request->input('password'));
        $user->must_change_password = false;
        $user->save();

        return redirect()
            ->route('welcome')
            ->with('success', 'ตั้งรหัสผ่านสำเร็จ');
    }

    private function ensureLoginIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'username' => "พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ {$seconds} วินาที แล้วลองใหม่",
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return User::normalizeUsername($request->input('username')).'|'.$request->ip();
    }
}
