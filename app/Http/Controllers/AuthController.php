<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuditTrail;
use App\Support\PasswordPolicy;
use App\Support\UserSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** จำนวนครั้งที่ยอมให้พยายามเข้าสู่ระบบก่อนถูกล็อกชั่วคราว */
    private const MAX_LOGIN_ATTEMPTS = 5;

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

            AuditTrail::authEvent('login_failed', null, $credentials['username']);

            // บันทึกการล็อกครั้งเดียวตอนข้ามเกณฑ์ ไม่ใช่ทุกครั้งที่ถูกบล็อกหลังจากนั้น
            // มิฉะนั้นการยิงซ้ำ ๆ จะทำให้บันทึกตรวจสอบเต็มไปด้วยรายการเดียวกัน
            if (RateLimiter::attempts($throttleKey) === self::MAX_LOGIN_ATTEMPTS) {
                AuditTrail::authEvent('login_locked', null, $credentials['username']);
            }

            return back()
                ->withErrors([
                    'username' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง',
                ])
                ->onlyInput('username');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
        AuditTrail::authEvent('login', $user, $credentials['username']);

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
            // session()->regenerate() เปลี่ยนแค่ session ID ไม่ล้างค่าเดิม (Store::migrate(false))
            // จึงต้องเซ็ตมุมมองตั้งต้นให้ชัดเจน มิฉะนั้นผู้ที่เคยเลือกตารางไว้จะไม่ได้ปฏิทิน
            $request->session()->put(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'calendar');

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
        // ต้องบันทึกก่อน Auth::logout() มิฉะนั้นจะไม่เหลือผู้ใช้ให้ผูกกับรายการนี้
        if ($user = $request->user()) {
            AuditTrail::authEvent('logout', $user, $user->username ?? '');
        }

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
            ...PasswordPolicy::messages(),
        ]);

        UserSessionSecurity::assertSupportedDriver();
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        DB::transaction(function () use ($user, $request, $currentSessionId): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            abort_unless($lockedUser->must_change_password, 403);

            $lockedUser->forceFill([
                'password' => Hash::make($request->input('password')),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            UserSessionSecurity::invalidateOthers($lockedUser, $currentSessionId);
        });

        $user->refresh();
        Auth::setUser($user);
        $request->session()->regenerate();

        return redirect()
            ->route('welcome')
            ->with('success', 'ตั้งรหัสผ่านสำเร็จ');
    }

    private function ensureLoginIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_LOGIN_ATTEMPTS)) {
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
