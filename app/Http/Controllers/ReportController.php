<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\AdminReportService;
use App\Services\EmployeeReportService;
use App\Services\PersonalReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly PersonalReportService $personalReports) {}

    public function index(Request $request)
    {
        $this->authorizeAdminReports();

        if ($request->query()) {
            return redirect()->route('reports.organization', $request->query());
        }

        return view('reports.index');
    }

    public function organization(Request $request, AdminReportService $reports)
    {
        $this->authorizeAdminReports();

        return view('reports.organization', $reports->build($request));
    }

    public function exportCsv(Request $request, AdminReportService $reports): StreamedResponse
    {
        $this->authorizeAdminReports();

        return $this->downloadJobsCsv(
            $reports->exportJobs($request),
            'smart-goals-report-'.now()->format('Ymd-His').'.csv',
        );
    }

    public function employees(Request $request)
    {
        $this->authorizeAdminReports();

        $departments = Department::query()
            ->withCount(['users as active_users_count' => fn ($query) => $query
                ->where('role', 'user')
                ->where('is_active', true)])
            ->orderBy('department_name')
            ->get();
        $departmentId = $request->integer('department');
        $departmentId = $departments->contains('id', $departmentId) ? $departmentId : null;
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);

        $employees = User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($departmentId, fn ($query, int $id) => $query->where('department_id', $id))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);

        return view('reports.employees.index', compact(
            'departments',
            'departmentId',
            'search',
            'employees',
        ));
    }

    public function myReport(Request $request)
    {
        abort_unless(Auth::user()?->role === 'user', 403);

        return view('reports.my', $this->personalReports->build(Auth::user(), $request));
    }

    public function exportMyCsv(Request $request): StreamedResponse
    {
        abort_unless(Auth::user()?->role === 'user', 403);

        return $this->downloadEmployeeCsv(Auth::user(), (int) $request->query('year', now()->year));
    }

    public function employeeReport(Request $request, User $user, EmployeeReportService $reports)
    {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        return view('reports.employee', $reports->build($user, $request));
    }

    public function exportEmployeeCsv(Request $request, User $user, EmployeeReportService $reports): StreamedResponse
    {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        return $this->downloadJobsCsv(
            $reports->exportJobs($user, $request),
            'smart-goals-'.$user->id.'-'.$request->string('period', 'last_6_months')->toString().'.csv',
        );
    }

    private function downloadEmployeeCsv(User $user, int $year): StreamedResponse
    {
        return $this->downloadJobsCsv(
            $this->personalReports->queryFor($user->id)
                ->with(['user', 'department'])
                ->whereYear('created_at', $year)
                ->orderBy('job_id'),
            'smart-goals-'.$user->id.'-'.$year.'.csv',
        );
    }

    private function downloadJobsCsv($jobs, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($jobs): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['เลขงาน', 'ชื่องาน', 'ผู้รับผิดชอบ', 'แผนก', 'สถานะอนุมัติ', 'สถานะงาน', 'ความคืบหน้า', 'วันที่เริ่ม', 'กำหนดส่ง', 'วันที่เสร็จ']);

            $writeRows = function ($chunk) use ($handle): void {
                foreach ($chunk as $job) {
                    fputcsv($handle, [
                        'IT-'.$job->job_id,
                        $job->job_topic,
                        optional($job->user)->name,
                        optional($job->department)->department_name,
                        $this->approvalLabel($job->approval_status),
                        $this->statusLabel((int) $job->job_status),
                        $job->job_progress.'%',
                        optional($job->job_start_at)->format('Y-m-d H:i'),
                        optional($job->job_due_at)->format('Y-m-d H:i'),
                        optional($job->job_completed_at)->format('Y-m-d H:i'),
                    ]);
                }
            };

            if ($jobs instanceof Collection) {
                $jobs->chunk(200)->each($writeRows);
            } else {
                $jobs->chunk(200, $writeRows);
            }

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function statusLabel(int $status): string
    {
        return [
            1 => 'รอดำเนินการ',
            2 => 'กำลังทำ',
            3 => 'ตรวจสอบ',
            4 => 'เสร็จสิ้น',
            5 => 'พักงานชั่วคราว',
            6 => 'ล่าช้า',
        ][$status] ?? 'รอดำเนินการ';
    }

    private function approvalLabel(?string $status): string
    {
        return [
            'pending' => 'รออนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
        ][$status] ?? 'อนุมัติแล้ว';
    }

    private function authorizeAdminReports(): void
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);
    }

    private function ensureReportableEmployee(User $user): void
    {
        abort_unless($user->role === 'user' && $user->is_active, 404);
    }
}
