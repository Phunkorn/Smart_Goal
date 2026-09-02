<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\PasswordPolicy;
use App\Support\UserSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const CONTEXT_EMPLOYEE = 'employee';

    private const CONTEXT_SYSTEM = 'system';

    public function index()
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        $employees = User::with('department')
            ->where('role', 'user')
            ->orderBy('name')
            ->get()
            ->values();

        $departments = Department::orderBy('department_name')->get();
        $canManageEmployees = Auth::user()?->role === 'admin';

        return view('employees.index', compact('employees', 'departments', 'canManageEmployees') + [
            'accountContext' => self::CONTEXT_EMPLOYEE,
        ]);
    }

    public function systemAccountsIndex()
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $employees = User::with('department')
            ->whereIn('role', ['admin', 'viewer'])
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        return view('employees.index', [
            'employees' => $employees,
            'departments' => collect(),
            'canManageEmployees' => true,
            'accountContext' => self::CONTEXT_SYSTEM,
        ]);
    }

    public function storeSystemAccount(Request $request)
    {
        return $this->store($request, self::CONTEXT_SYSTEM);
    }

    public function store(Request $request, string $accountContext = self::CONTEXT_EMPLOYEE)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $this->validateUser($request, null, $accountContext);
        $profilePath = $this->storeProfileImage($request);

        $employee = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department_id' => $validated['role'] === 'user' ? $validated['department_id'] : null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_department_head' => $validated['role'] === 'user' && $validated['is_department_head'],
            'must_change_password' => true,
            'is_active' => $validated['is_active'],
            'profile_image' => $profilePath,
        ]);

        AuditTrail::log('created', $employee, 'Admin created '.$this->accountAuditLabel($accountContext).': '.$employee->name, [
            'after' => $this->auditUserPayload($employee),
        ]);

        if ($accountContext === self::CONTEXT_SYSTEM) {
            return redirect()->route('admin.accounts.index')->with('success', 'เพิ่มบัญชีระบบสำเร็จ');
        }

        return redirect()->route('employees.index')->with('success', 'เพิ่มพนักงานสำเร็จ');
    }

    public function updateSystemAccount(Request $request, User $user)
    {
        return $this->update($request, $user, self::CONTEXT_SYSTEM);
    }

    public function update(Request $request, User $user, string $accountContext = self::CONTEXT_EMPLOYEE)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
        $this->assertAccountContext($user, $accountContext);

        $validated = $this->validateUser($request, $user, $accountContext);
        if ($accountContext === self::CONTEXT_SYSTEM) {
            $this->assertSafeSystemAccountTransition($user, $validated);
        }
        $before = $this->auditUserPayload($user);
        $usernameChanged = $user->username !== $validated['username'];
        $emailChanged = $user->email !== $validated['email'];

        $data = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'department_id' => $validated['role'] === 'user' ? $validated['department_id'] : null,
            'is_department_head' => $validated['role'] === 'user' && $validated['is_department_head'],
            'is_active' => $validated['is_active'],
        ];

        if ($emailChanged) {
            $data['email_verified_at'] = null;
        }

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
            $data['must_change_password'] = true;
        }

        $credentialsChanged = $usernameChanged || array_key_exists('password', $data);

        if ($credentialsChanged || ! $validated['is_active']) {
            $data['remember_token'] = Str::random(60);
            UserSessionSecurity::assertSupportedDriver();
        }

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $data['profile_image'] = $this->storeProfileImage($request);
        }

        $user->forceFill($data)->save();
        $user->refresh();

        if (! $user->is_active || $credentialsChanged) {
            UserSessionSecurity::invalidateAll($user);
        }

        AuditTrail::log('updated', $user, 'Admin updated '.$this->accountAuditLabel($accountContext).': '.$user->name, [
            'before' => $before,
            'after' => $this->auditUserPayload($user),
        ]);

        if ($accountContext === self::CONTEXT_SYSTEM
            && ! ($usernameChanged && (int) $user->id === (int) Auth::id())) {
            return redirect()->route('admin.accounts.index')->with('success', 'แก้ไขบัญชีระบบสำเร็จ');
        }

        if ($usernameChanged && (int) $user->id === (int) Auth::id()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()->route('employees.index')->with('success', 'แก้ไขข้อมูลพนักงานสำเร็จ');
    }

    public function destroySystemAccount(User $user)
    {
        return $this->destroy($user, self::CONTEXT_SYSTEM);
    }

    public function destroy(User $user, string $accountContext = self::CONTEXT_EMPLOYEE)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
        $this->assertAccountContext($user, $accountContext);

        if ($user->id === Auth::id()) {
            return back()->withErrors(['user' => 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้']);
        }

        if ($accountContext === self::CONTEXT_SYSTEM && $this->isLastActiveAdmin($user)) {
            return back()->withErrors(['user' => 'ระบบต้องมีบัญชีผู้ดูแลที่เปิดใช้งานอย่างน้อย 1 บัญชี']);
        }

        $hasJobs = WorkOrder::where('user_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhere('leader_user_id', $user->id)
            ->orWhereHas('collaborators', fn ($query) => $query->where('users.id', $user->id))
            ->exists();

        if ($hasJobs) {
            $message = $accountContext === self::CONTEXT_SYSTEM
                ? 'บัญชีนี้ยังมีข้อมูลงานผูกอยู่ กรุณาปิดงานหรือย้ายผู้รับผิดชอบก่อนลบ'
                : 'พนักงานคนนี้ยังมีข้อมูลงานผูกอยู่ กรุณาปิดงานหรือย้ายผู้รับผิดชอบก่อนลบ';

            return back()->withErrors(['user' => $message]);
        }

        UserSessionSecurity::assertSupportedDriver();
        $payload = $this->auditUserPayload($user);

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
        }

        AuditTrail::trash($user, Auth::user(), ['user' => $payload]);
        AuditTrail::log('deleted', $user, 'Admin deleted '.$this->accountAuditLabel($accountContext).': '.$user->name, [
            'before' => $payload,
        ]);

        $user->delete();
        UserSessionSecurity::invalidateAll($user);

        if ($accountContext === self::CONTEXT_SYSTEM) {
            return redirect()->route('admin.accounts.index')->with('success', 'ลบบัญชีระบบสำเร็จ');
        }

        return redirect()->route('employees.index')->with('success', 'ลบพนักงานสำเร็จ');
    }

    public function resetSystemAccountPassword(Request $request, User $user)
    {
        return $this->resetPassword($request, $user, self::CONTEXT_SYSTEM);
    }

    public function resetPassword(Request $request, User $user, string $accountContext = self::CONTEXT_EMPLOYEE)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
        $this->assertAccountContext($user, $accountContext);

        $validated = $request->validate([
            'password' => ['required', 'string', PasswordPolicy::rule()],
        ], [
            'password.required' => 'กรุณากรอกรหัสผ่านชั่วคราว',
            ...PasswordPolicy::messages(),
        ]);

        UserSessionSecurity::assertSupportedDriver();
        $before = $this->auditUserPayload($user);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();
        UserSessionSecurity::invalidateAll($user);

        AuditTrail::log('password_reset', $user, 'Admin reset password for '.$this->accountAuditLabel($accountContext).': '.$user->name, [
            'before' => $before,
            'after' => $this->auditUserPayload($user),
        ]);

        if ($accountContext === self::CONTEXT_SYSTEM) {
            return redirect()->route('admin.accounts.index')->with('success', 'ตั้งรหัสผ่านชั่วคราวให้บัญชีระบบสำเร็จ');
        }

        return redirect()->route('employees.index')->with('success', 'ตั้งรหัสผ่านชั่วคราวให้พนักงานสำเร็จ พนักงานต้องตั้งรหัสผ่านใหม่ในการเข้าสู่ระบบครั้งถัดไป');
    }

    private function validateUser(Request $request, ?User $user = null, string $accountContext = self::CONTEXT_EMPLOYEE): array
    {
        $isDepartmentHead = $accountContext === self::CONTEXT_EMPLOYEE
            && $request->input('role') === 'department_head';
        $request->merge([
            'username' => User::normalizeUsername($request->input('username')),
            'email' => $request->filled('email') ? trim((string) $request->input('email')) : null,
            'role' => $isDepartmentHead ? 'user' : $request->input('role'),
            'department_id' => $accountContext === self::CONTEXT_SYSTEM ? null : $request->input('department_id'),
            'is_department_head' => $isDepartmentHead,
        ]);

        if ($user) {
            $passwordRule = ['nullable', 'string', 'confirmed', PasswordPolicy::rule()];
        } else {
            $passwordRule = ['required', 'string'];
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/\A[a-z0-9._-]+\z/',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => $passwordRule,
            'role' => ['required', Rule::in($accountContext === self::CONTEXT_SYSTEM ? ['admin', 'viewer'] : ['user'])],
            'is_department_head' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'department_id' => $accountContext === self::CONTEXT_EMPLOYEE
                ? ['nullable', 'required_if:role,user', 'exists:departments,id']
                : ['nullable'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'password.confirmed' => 'รหัสผ่านทั้งสองช่องไม่ตรงกัน',
            ...PasswordPolicy::messages(),
        ]);
    }

    private function assertAccountContext(User $user, string $accountContext): void
    {
        $matches = $accountContext === self::CONTEXT_SYSTEM
            ? in_array($user->role, ['admin', 'viewer'], true)
            : $user->role === 'user';

        abort_unless($matches, 404);
    }

    private function assertSafeSystemAccountTransition(User $user, array $validated): void
    {
        if ((int) $user->id === (int) Auth::id()
            && ($validated['role'] !== 'admin' || ! $validated['is_active'])) {
            throw ValidationException::withMessages([
                'user' => 'ไม่สามารถลดสิทธิ์หรือปิดใช้งานบัญชีผู้ดูแลที่กำลังใช้งานอยู่ได้',
            ]);
        }

        if ($this->isLastActiveAdmin($user)
            && ($validated['role'] !== 'admin' || ! $validated['is_active'])) {
            throw ValidationException::withMessages([
                'user' => 'ระบบต้องมีบัญชีผู้ดูแลที่เปิดใช้งานอย่างน้อย 1 บัญชี',
            ]);
        }
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return $user->role === 'admin'
            && $user->is_active
            && ! User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->exists();
    }

    private function accountAuditLabel(string $accountContext): string
    {
        return $accountContext === self::CONTEXT_SYSTEM ? 'system account' : 'employee';
    }

    private function storeProfileImage(Request $request): ?string
    {
        return $request->hasFile('profile_image')
            ? $request->file('profile_image')->store('profiles', 'public')
            : null;
    }

    private function auditUserPayload(User $user): array
    {
        $payload = $user->attributesToArray();
        unset($payload['password'], $payload['remember_token']);

        return $payload;
    }
}
