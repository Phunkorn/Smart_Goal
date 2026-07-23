<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index()
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        $jobs = WorkOrder::with(['user', 'department'])->get();
        $totalJobs = $jobs->count();
        $completedJobs = $jobs->where('job_status', 4)->count();
        $pendingApproval = $jobs->where('approval_status', 'pending')->count();
        $overdueJobs = $jobs->filter(fn ($job) => $this->isOverdue($job))->count();
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100) : 0;

        $statusSummary = collect([
            ['label' => 'รออนุมัติ', 'value' => $pendingApproval, 'tone' => 'amber'],
            ['label' => 'รอดำเนินการ', 'value' => $jobs->where('job_status', 1)->count(), 'tone' => 'gray'],
            ['label' => 'กำลังทำ', 'value' => $jobs->where('job_status', 2)->count(), 'tone' => 'blue'],
            ['label' => 'ตรวจสอบ', 'value' => $jobs->where('job_status', 3)->count(), 'tone' => 'purple'],
            ['label' => 'พักงานชั่วคราว', 'value' => $jobs->where('job_status', 5)->count(), 'tone' => 'gray'],
            ['label' => 'เสร็จสิ้น', 'value' => $completedJobs, 'tone' => 'green'],
            ['label' => 'ล่าช้า', 'value' => $overdueJobs, 'tone' => 'red'],
        ]);

        $departmentSummary = Department::withCount('users')->orderBy('department_name')->get()->map(function ($department) use ($jobs) {
            $departmentJobs = $jobs->where('department_id', $department->id);
            $total = $departmentJobs->count();
            $done = $departmentJobs->where('job_status', 4)->count();

            return [
                'name' => $department->department_name,
                'employees' => $department->users_count,
                'total' => $total,
                'active' => $departmentJobs->where('job_status', '!=', 4)->count(),
                'done' => $done,
                'overdue' => $departmentJobs->filter(fn ($job) => $this->isOverdue($job))->count(),
                'rate' => $total > 0 ? round(($done / $total) * 100) : 0,
            ];
        });

        $employeeSummary = User::with('department')->orderBy('name')->get()->map(function ($user) use ($jobs) {
            $userJobs = $jobs->where('user_id', $user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'department' => optional($user->department)->department_name ?? '-',
                'total' => $userJobs->count(),
                'active' => $userJobs->where('job_status', '!=', 4)->count(),
                'done' => $userJobs->where('job_status', 4)->count(),
            ];
        })->sortByDesc('active')->values();

        $monthlySummary = collect(range(5, 0))->map(function ($offset) use ($jobs) {
            $month = now()->subMonths($offset);

            return [
                'label' => $month->locale('th')->isoFormat('MMM YYYY'),
                'created' => $jobs->filter(fn ($job) => $job->created_at && $job->created_at->isSameMonth($month))->count(),
                'done' => $jobs->filter(fn ($job) => $job->job_completed_at && $job->job_completed_at->isSameMonth($month))->count(),
            ];
        });

        return view('reports.index', compact(
            'totalJobs',
            'completedJobs',
            'pendingApproval',
            'overdueJobs',
            'completionRate',
            'statusSummary',
            'departmentSummary',
            'employeeSummary',
            'monthlySummary'
        ));
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        return $this->downloadJobsCsv(
            WorkOrder::with(['user', 'department'])->orderBy('job_id'),
            'smart-goals-report-'.now()->format('Ymd-His').'.csv'
        );
    }

    public function myReport(Request $request)
    {
        abort_unless(Auth::user()?->role === 'user', 403);

        return $this->personalReportView(Auth::user(), $request, 'reports.my');
    }

    public function exportMyCsv(Request $request): StreamedResponse
    {
        abort_unless(Auth::user()?->role === 'user', 403);

        return $this->downloadEmployeeCsv(Auth::user(), (int) $request->query('year', now()->year));
    }

    public function employeeReport(Request $request, User $user)
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        return $this->personalReportView($user, $request, 'reports.employee');
    }

    public function exportEmployeeCsv(Request $request, User $user): StreamedResponse
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        return $this->downloadEmployeeCsv($user, (int) $request->query('year', now()->year));
    }

    private function personalReportView(User $user, Request $request, string $view)
    {
        $year = (int) $request->query('year', now()->year);
        $availableYears = $this->personalJobsQuery($user->id)
            ->whereNotNull('created_at')
            ->pluck('created_at')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->year)
            ->unique()
            ->sortDesc()
            ->values();

        if ($availableYears->isEmpty()) {
            $availableYears = collect([now()->year]);
        }

        if (! $availableYears->contains($year)) {
            $year = $availableYears->first();
        }

        $jobs = $this->personalJobsQuery($user->id)
            ->with(['user', 'department', 'creator', 'leader'])
            ->whereYear('created_at', $year)
            ->orderByDesc('created_at')
            ->get();

        $completedJobs = $jobs->where('job_status', 4);
        $activeJobs = $jobs->whereIn('job_status', [1, 2, 3, 5]);
        $overdueJobs = $jobs->filter(fn ($job) => $this->isOverdue($job));

        $monthlySummary = collect(range(1, 12))->map(function ($month) use ($jobs, $year) {
            $monthDate = Carbon::create($year, $month, 1);
            $monthJobs = $jobs->filter(fn ($job) => $job->created_at && $job->created_at->isSameMonth($monthDate));

            return [
                'label' => $monthDate->locale('th')->isoFormat('MMM'),
                'total' => $monthJobs->count(),
                'done' => $monthJobs->where('job_status', 4)->count(),
            ];
        });

        $statusSummary = collect([
            ['label' => 'รอดำเนินการ', 'value' => $jobs->where('job_status', 1)->count(), 'tone' => 'amber'],
            ['label' => 'กำลังทำ', 'value' => $jobs->where('job_status', 2)->count(), 'tone' => 'purple'],
            ['label' => 'ตรวจสอบ', 'value' => $jobs->where('job_status', 3)->count(), 'tone' => 'blue'],
            ['label' => 'พักงานชั่วคราว', 'value' => $jobs->where('job_status', 5)->count(), 'tone' => 'gray'],
            ['label' => 'เสร็จสิ้น', 'value' => $completedJobs->count(), 'tone' => 'green'],
            ['label' => 'ล่าช้า', 'value' => $overdueJobs->count(), 'tone' => 'red'],
        ]);

        return view($view, [
            'employee' => $user->load('department'),
            'year' => $year,
            'availableYears' => $availableYears,
            'jobs' => $jobs,
            'monthlySummary' => $monthlySummary,
            'statusSummary' => $statusSummary,
            'totalJobs' => $jobs->count(),
            'thisMonthJobs' => $jobs->filter(fn ($job) => $job->created_at && $job->created_at->isSameMonth(now()))->count(),
            'completedJobs' => $completedJobs->count(),
            'activeJobs' => $activeJobs->count(),
            'overdueJobs' => $overdueJobs->count(),
        ]);
    }

    private function personalJobsQuery(int $userId)
    {
        return WorkOrder::query()
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('created_by', $userId)
                    ->orWhere('leader_user_id', $userId)
                    ->orWhereHas('collaborators', function ($collaboratorQuery) use ($userId) {
                        $collaboratorQuery
                            ->where('users.id', $userId)
                            ->where('work_order_collaborators.status', 'accepted');
                    });
            });
    }

    private function downloadEmployeeCsv(User $user, int $year): StreamedResponse
    {
        return $this->downloadJobsCsv(
            $this->personalJobsQuery($user->id)->with(['user', 'department'])->whereYear('created_at', $year)->orderBy('job_id'),
            'smart-goals-'.$user->id.'-'.$year.'.csv'
        );
    }

    private function downloadJobsCsv($query, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['เลขงาน', 'ชื่องาน', 'ผู้รับผิดชอบ', 'แผนก', 'สถานะอนุมัติ', 'สถานะงาน', 'ความคืบหน้า', 'วันที่เริ่ม', 'กำหนดส่ง', 'วันที่เสร็จ']);

            $query->chunk(200, function ($jobs) use ($handle) {
                foreach ($jobs as $job) {
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
            });

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

    private function isOverdue(WorkOrder $job): bool
    {
        return $job->job_due_at && (int) $job->job_status !== 4 && $job->job_due_at->lt(now());
    }
}
