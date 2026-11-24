<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use App\Services\ProfileAppearanceService;
use App\Support\AuditTrail;
use App\Support\PasswordPolicy;
use App\Support\ProfileAppearance;
use App\Support\UserSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('security_headers')) {
            abort_unless($request->user()->role === 'admin', 403);

            // Reuse the real policy builder; never expose .env, tokens or full forwarded headers.
            $preview = app(SecurityHeaders::class)->apply($request, response(''));
            $headers = [];
            foreach (['Strict-Transport-Security', 'Content-Security-Policy', 'Content-Security-Policy-Report-Only',
                'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'] as $name) {
                $headers[$name] = $preview->headers->get($name);
            }

            return response()->json([
                'environment' => app()->environment(),
                'headers_enabled' => (bool) config('security.enabled'),
                'csp_report_only' => (bool) config('security.csp.report_only'),
                'hsts_enabled' => (bool) config('security.hsts.enabled'),
                'request' => [
                    'is_secure' => $request->isSecure(),
                    'scheme' => $request->getScheme(),
                    'peer_address' => $request->server('REMOTE_ADDR'),
                    'peer_is_trusted' => $request->isFromTrustedProxy(),
                    'forwarded_proto' => in_array($request->header('X-Forwarded-Proto'), ['http', 'https'], true)
                        ? $request->header('X-Forwarded-Proto') : null,
                    'server_https' => in_array(strtolower((string) $request->server('HTTPS')), ['on', '1'], true),
                ],
                'headers' => $headers,
            ])->header('Cache-Control', 'private, no-store');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'telegram_html' => $request->user()->role === 'viewer' ? '' : view('settings.components.telegram-card', [
                    'user' => $request->user(),
                ])->render(),
            ]);
        }

        return view('settings.index', ['user' => auth()->user()->load('department')]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $user->name = $validated['name'];
        $user->phone = $validated['phone'] ?? null;

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $user->profile_image = $request->file('profile_image')->store('profiles', 'public');
        }

        $user->save();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว',
                'user' => [
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'initials' => mb_substr($user->name, 0, 2),
                    'avatar_url' => $user->profile_image ? route('media.profile', $user) : null,
                ],
            ]);
        }

        return back()->with('success', 'บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว');
    }

    public function updateAppearance(Request $request, ProfileAppearanceService $appearance)
    {
        $cover = config('profile-appearance.cover');
        $validated = $request->validateWithBag('appearance', [
            'avatar_frame' => ['required', Rule::in(['none', ...array_keys(ProfileAppearance::frames())])],
            'profile_cover' => ['nullable', 'prohibited_if:remove_profile_cover,1,true', 'image',
                'mimes:'.implode(',', $cover['mime_types']), 'max:'.$cover['max_kilobytes'],
                'dimensions:max_width='.$cover['max_dimension'].',max_height='.$cover['max_dimension']],
            'remove_profile_cover' => ['sometimes', 'boolean'],
        ]);
        $user = $appearance->update($request->user(), $validated, $request->file('profile_cover'));
        $message = 'บันทึกการตกแต่งโปรไฟล์เรียบร้อยแล้ว';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'appearance' => [
                    'cover_url' => ProfileAppearance::coverUrl($user),
                    'avatar_frame' => ProfileAppearance::frame($user),
                ],
            ]);
        }

        return back()->with('success', $message);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ], [
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.confirmed' => 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน',
            ...PasswordPolicy::messages(),
        ]);

        UserSessionSecurity::assertSupportedDriver();
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        DB::transaction(function () use ($user, $validated, $currentSessionId): void {
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
            ])->save();

            UserSessionSecurity::invalidateOthers($user, $currentSessionId);
            AuditTrail::log('password_changed', $user, 'User changed their own password');
        });

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว อุปกรณ์อื่นถูกนำออกจากระบบ']);
        }

        return back()->with('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว อุปกรณ์อื่นถูกนำออกจากระบบ');
    }
}
