<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Support\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $departments = Department::query()
            ->withCount([
                'users' => fn ($query) => $query->withTrashed(),
                'jobs' => fn ($query) => $query->withTrashed(),
            ])
            ->orderBy('department_name')
            ->get();

        return view('admin.departments.index', compact('departments'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $departmentName = $this->validatedDepartmentName($request);

        DB::transaction(function () use ($departmentName): void {
            $department = Department::create([
                'department_name' => $departmentName,
            ]);

            AuditTrail::log('created', $department, 'Admin created department: '.$departmentName, [
                'after' => ['department_name' => $departmentName],
            ]);
        });

        return redirect()->route('admin.departments.index')
            ->with('success', 'เพิ่มแผนกสำเร็จ');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $departmentName = $this->validatedDepartmentName($request, $department);

        DB::transaction(function () use ($department, $departmentName): void {
            $lockedDepartment = Department::query()->lockForUpdate()->findOrFail($department->id);
            $before = $lockedDepartment->department_name;

            $lockedDepartment->update([
                'department_name' => $departmentName,
            ]);

            AuditTrail::log('updated', $lockedDepartment, 'Admin updated department: '.$departmentName, [
                'before' => ['department_name' => $before],
                'after' => ['department_name' => $departmentName],
            ]);
        });

        return redirect()->route('admin.departments.index')
            ->with('success', 'แก้ไขชื่อแผนกสำเร็จ');
    }

    public function destroy(Department $department): RedirectResponse
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $deleted = DB::transaction(function () use ($department): bool {
            $lockedDepartment = Department::query()->lockForUpdate()->findOrFail($department->id);
            $hasUsers = $lockedDepartment->users()->withTrashed()->exists();
            $hasJobs = $lockedDepartment->jobs()->withTrashed()->exists();

            if ($hasUsers || $hasJobs) {
                return false;
            }

            AuditTrail::log('deleted', $lockedDepartment, 'Admin deleted department: '.$lockedDepartment->department_name, [
                'before' => [
                    'id' => $lockedDepartment->id,
                    'department_name' => $lockedDepartment->department_name,
                ],
            ]);

            $lockedDepartment->delete();

            return true;
        });

        if (! $deleted) {
            return back()->withErrors([
                'department' => 'ไม่สามารถลบแผนกนี้ได้ เนื่องจากยังมีพนักงานหรือข้อมูลงานที่เชื่อมโยงอยู่',
            ]);
        }

        return redirect()->route('admin.departments.index')
            ->with('success', 'ลบแผนกสำเร็จ');
    }

    private function validatedDepartmentName(Request $request, ?Department $currentDepartment = null): string
    {
        if (is_string($request->input('department_name'))) {
            $request->merge([
                'department_name' => trim($request->input('department_name')),
            ]);
        }

        $validated = $request->validate([
            'department_name' => [
                'bail',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($currentDepartment): void {
                    $normalized = mb_strtolower(trim((string) $value));

                    $duplicateExists = Department::query()
                        ->when($currentDepartment, fn ($query) => $query->where('id', '!=', $currentDepartment->id))
                        ->get(['id', 'department_name'])
                        ->contains(fn (Department $department) => mb_strtolower(trim($department->department_name)) === $normalized);

                    if ($duplicateExists) {
                        $fail('ชื่อแผนกนี้มีอยู่ในระบบแล้ว');
                    }
                },
            ],
        ], [
            'department_name.required' => 'กรุณากรอกชื่อแผนก',
            'department_name.string' => 'ชื่อแผนกต้องเป็นข้อความ',
            'department_name.max' => 'ชื่อแผนกต้องยาวไม่เกิน 255 ตัวอักษร',
        ]);

        return $validated['department_name'];
    }
}
