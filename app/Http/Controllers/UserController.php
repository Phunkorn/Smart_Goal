<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        $employees = User::with(['jobs' => fn ($q) => $q->latest('job_id'), 'department'])
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $user) => ['admin' => 0, 'viewer' => 1, 'user' => 2][$user->role] ?? 3)
            ->values();

        $departments = Department::orderBy('department_name')->get();
        $canManageEmployees = Auth::user()?->role === 'admin';
        $roleCounts = [
            'admin' => $employees->where('role', 'admin')->count(),
            'viewer' => $employees->where('role', 'viewer')->count(),
            'user' => $employees->where('role', 'user')->count(),
        ];

        return view('employees.index', compact('employees', 'departments', 'canManageEmployees', 'roleCounts'));
    }

    public function show(User $user)
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        $user->load(['jobs' => fn ($q) => $q->latest('job_id'), 'department']);

        $activeJob = $user->jobs->first(fn ($job) => in_array((int) $job->job_status, [1, 2, 3, 5], true));
        $history = $user->jobs;
        $completedCount = $user->jobs->where('job_status', 4)->count();

        return view('employees.show', compact('user', 'activeJob', 'history', 'completedCount'));
    }

    public function store(Request $request)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $this->validateUser($request);
        $profilePath = $this->storeProfileImage($request);

        $employee = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department_id' => $validated['role'] === 'user' ? $validated['department_id'] : null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'must_change_password' => true,
            'profile_image' => $profilePath,
        ]);

        AuditTrail::log('created', $employee, 'Admin created employee: ' . $employee->name, [
            'after' => $this->auditUserPayload($employee),
        ]);

        return redirect()->route('employees.index')->with('success', 'เพิ่มพนักงานสำเร็จ');
    }

    public function update(Request $request, User $user)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $this->validateUser($request, $user);
        $before = $this->auditUserPayload($user);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'department_id' => $validated['role'] === 'user' ? $validated['department_id'] : null,
        ];

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
            $data['must_change_password'] = true;
        }

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $data['profile_image'] = $this->storeProfileImage($request);
        }

        $user->update($data);
        $user->refresh();

        AuditTrail::log('updated', $user, 'Admin updated employee: ' . $user->name, [
            'before' => $before,
            'after' => $this->auditUserPayload($user),
        ]);

        return redirect()->route('employees.index')->with('success', 'แก้ไขข้อมูลพนักงานสำเร็จ');
    }

    public function destroy(User $user)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        if ($user->id === Auth::id()) {
            return back()->withErrors(['user' => 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้']);
        }

        $hasJobs = WorkOrder::where('user_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhere('leader_user_id', $user->id)
            ->orWhereHas('collaborators', fn ($query) => $query->where('users.id', $user->id))
            ->exists();

        if ($hasJobs) {
            return back()->withErrors(['user' => 'พนักงานคนนี้ยังมีข้อมูลงานผูกอยู่ กรุณาปิดงานหรือย้ายผู้รับผิดชอบก่อนลบ']);
        }

        $payload = $this->auditUserPayload($user);

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
        }

        AuditTrail::trash($user, Auth::user(), ['user' => $payload]);
        AuditTrail::log('deleted', $user, 'Admin deleted employee: ' . $user->name, [
            'before' => $payload,
        ]);

        $user->delete();

        return redirect()->route('employees.index')->with('success', 'ลบพนักงานสำเร็จ');
    }

    public function resetPassword(Request $request, User $user)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ], [
            'password.required' => 'กรุณากรอกรหัสผ่านชั่วคราว',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร',
        ]);

        $before = $this->auditUserPayload($user);

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
        ]);

        AuditTrail::log('password_reset', $user, 'Admin reset password for employee: ' . $user->name, [
            'before' => $before,
            'after' => $this->auditUserPayload($user),
        ]);

        return redirect()->route('employees.index')->with('success', 'ตั้งรหัสผ่านชั่วคราวให้พนักงานสำเร็จ พนักงานต้องตั้งรหัสผ่านใหม่ในการเข้าสู่ระบบครั้งถัดไป');
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $passwordRule = $user ? ['nullable', 'string', 'min:6'] : ['required', 'string', 'min:6'];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => $passwordRule,
            'role' => ['required', Rule::in(['admin', 'user', 'viewer'])],
            'department_id' => ['nullable', 'required_if:role,user', 'exists:departments,id'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
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