<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\AdminReportService;
use App\Services\EmployeeReportService;
use App\Services\OperationalWorkloadReportService;
use App\Services\PersonalReportService;
use App\Support\TaskTeamSummary;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly PersonalReportService $personalReports) {}

    /**
     * หน้าเลือกประเภทรายงาน
     *
     * เดิมเป็นหน้าของ admin / หัวหน้าแผนก / viewer เท่านั้น พนักงานทั่วไปถูกส่งตรง
     * ไปรายงานของตัวเองโดยไม่มีทางเลือก ตอนนี้พนักงานก็เข้าหน้านี้ได้ แต่เห็นเพียง
     * สองการ์ดที่ตัวเองมีสิทธิ์จริง (รายงานตัวเอง และรายงานปฏิบัติงานของตัวเอง)
     *
     * รายการการ์ดถูกประกอบที่ฝั่งเซิร์ฟเวอร์ที่เดียว ไม่ใช่ให้ Blade เดาจาก role
     * เพราะ "ใครเห็นการ์ดไหน" เป็นเรื่องสิทธิ์ ไม่ใช่เรื่องการแสดงผล
     */
    public function index(Request $request)
    {
        $this->authorizeAnyReports();

        if ($request->query() && $this->canSeeOrganizationReports()) {
            return redirect()->route('reports.organization', $request->query());
        }

        return view('reports.index', ['cards' => $this->landingCards()]);
    }

    public function organization(Request $request, AdminReportService $reports)
    {
        $this->authorizeAdminReports();

        return view('reports.organization', $reports->build($request, $this->forcedDepartmentId()));
    }

    public function exportCsv(Request $request, AdminReportService $reports): StreamedResponse
    {
        $this->authorizeAdminReports();

        return $this->downloadJobsCsv(
            $reports->exportJobs($request, $this->forcedDepartmentId()),
            'smart-goals-report-'.now()->format('Ymd-His').'.csv',
        );
    }

    /**
     * รายงานภาระงานปฏิบัติการ — งานประจำและงานนอกสถานที่
     *
     * ใช้ตัวตรวจสิทธิ์ของตัวเอง ไม่ใช่ authorizeAdminReports() เพราะรายงานนี้
     * เป็นข้อมูลรายบุคคลที่ละเอียดกว่าภาพรวมองค์กร viewer จึงต้องไม่เห็น
     */
    public function operational(Request $request, OperationalWorkloadReportService $reports)
    {
        $this->authorizeOperationalReports();

        return view('reports.operational', $reports->build(
            $request,
            $this->forcedDepartmentId(),
            $this->forcedOwnerId()
        ));
    }

    public function exportOperationalCsv(
        Request $request,
        OperationalWorkloadReportService $reports
    ): StreamedResponse {
        $this->authorizeOperationalReports();

        return $this->downloadWorkLogsCsv(
            $reports->exportRows($request, $this->forcedDepartmentId(), $this->forcedOwnerId()),
            'smart-goals-operational-'.now()->format('Ymd-His').'.csv',
        );
    }

    public function employees(Request $request)
    {
        $this->authorizeAdminReports();

        $viewer = Auth::user();
        $isDepartmentHead = (bool) $viewer?->isDepartmentHead();
        $excludedEmployeeId = $isDepartmentHead ? (int) $viewer->id : null;

        $departments = Department::query()
            ->when($this->forcedDepartmentId(), fn ($query, int $id) => $query->whereKey($id))
            ->withCount(['users as active_users_count' => fn ($query) => $query
                ->where('role', 'user')
                ->where('is_active', true)
                ->when($excludedEmployeeId, fn ($users, int $id) => $users->whereKeyNot($id))])
            ->orderBy('department_name')
            ->get();
        $departmentId = $this->forcedDepartmentId() ?: $request->integer('department');
        $departmentId = $departments->contains('id', $departmentId) ? $departmentId : null;
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);

        $employees = User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($excludedEmployeeId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->when($departmentId, fn ($query, int $id) => $query->where('department_id', $id))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);

        return view('reports.employees.index', compact(
            'departments',
            'departmentId',
            'search',
            'employees',
            'isDepartmentHead',
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

    public function employeeReport(
        Request $request,
        User $user,
        EmployeeReportService $reports,
        OperationalWorkloadReportService $operational
    ) {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        if (Auth::user()?->isDepartmentHead() && Auth::id() === $user->id) {
            return redirect()->route('reports.my', $request->query());
        }

        $data = $reports->build($user, $request);

        return view('reports.employee', [
            ...$data,
            /*
             * บล็อกภาระงานปฏิบัติการบนหน้ารายงานรายบุคคล
             *
             * ตั้งใจแยกจากตัวเลขผลงานโครงการด้านบน ไม่รวมเป็นก้อนเดียวกัน เพราะ
             * ชั่วโมงงานปฏิบัติการไม่ใช่ผลงานโครงการ การรวมกันจะทำให้อัตราปิดงาน
             * และความคืบหน้าของโครงการเพี้ยน สองบล็อกนี้ตอบคนละคำถาม:
             * ด้านบนคือ "ทำโครงการได้ดีแค่ไหน" ด้านล่างคือ "เวลาที่เหลือไปอยู่ไหน"
             *
             * แสดงเฉพาะผู้ที่มีสิทธิ์ดูรายงานภาระงานปฏิบัติการ (admin และหัวหน้าแผนก)
             * เพื่อไม่ให้ viewer เห็นปุ่มที่กดแล้วเจอ 403
             */
            'operational' => Gate::allows('viewReport', WorkLog::class)
                ? $operational->forEmployee(
                    $user,
                    $data['filters']['start_date'],
                    $data['filters']['end_date']
                )
                : null,
            'operationalUrl' => route('reports.operational', array_filter([
                'owner' => $user->id,
                'department' => $user->department_id,
                'period' => $data['filters']['period'],
                'start_date' => $data['filters']['start_date'],
                'end_date' => $data['filters']['end_date'],
            ])),
        ]);
    }

    public function exportEmployeeCsv(Request $request, User $user, EmployeeReportService $reports): StreamedResponse
    {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        return $this->downloadJobsCsv(
            $reports->exportJobs($user, $request),
            'smart-goals-'.$user->id.'-'.$request->string('period', 'last_6_months')->toString().'.csv',
            $user->id,
        );
    }

    private function downloadEmployeeCsv(User $user, int $year): StreamedResponse
    {
        return $this->downloadJobsCsv(
            $this->personalReports->queryFor($user->id)
                ->with(['user', 'leader', 'assigner', 'creator', 'collaborators', 'department'])
                ->whereYear('created_at', $year)
                ->orderBy('job_id'),
            'smart-goals-'.$user->id.'-'.$year.'.csv',
            $user->id,
        );
    }

    /**
     * @param  int|null  $subjectUserId  เจ้าของรายงาน เมื่อระบุจะเพิ่มคอลัมน์บทบาท
     *                                   เพื่อให้แยกออกว่างานใบไหนรับผิดชอบเอง ใบไหนไปร่วมกับคนอื่น
     *                                   รายงานระดับองค์กรไม่มีเจ้าของคนเดียวจึงไม่มีคอลัมน์นี้
     */
    private function downloadJobsCsv($jobs, string $fileName, ?int $subjectUserId = null): StreamedResponse
    {
        return response()->streamDownload(function () use ($jobs, $subjectUserId): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $header = ['เลขงาน', 'ชื่องาน', 'ผู้รับผิดชอบ', 'แผนก', 'สถานะอนุมัติ', 'สถานะงาน', 'วันที่เริ่ม', 'กำหนดส่ง', 'วันที่เสร็จ'];

            if ($subjectUserId !== null) {
                array_splice($header, 3, 0, ['บทบาทของฉัน', 'หัวหน้าโปรเจกต์', 'มอบหมายโดย', 'ผู้ร่วมงาน']);
            }

            fputcsv($handle, $header);

            $writeRows = function ($chunk) use ($handle, $subjectUserId): void {
                foreach ($chunk as $job) {
                    $row = [
                        'IT-'.$job->job_id,
                        $job->job_topic,
                        optional($job->user)->name,
                        optional($job->department)->department_name,
                        $this->approvalLabel($job->approval_status),
                        $this->statusLabel((int) $job->job_status),
                        $this->csvDateTime($job->job_start_at),
                        $this->csvDateTime($job->job_due_at),
                        $this->csvDateTime($job->job_completed_at),
                    ];

                    if ($subjectUserId !== null) {
                        $team = TaskTeamSummary::for($job, $subjectUserId);
                        array_splice($row, 3, 0, [
                            $team['my_role']['label'],
                            $team['leader']['name'] ?? '',
                            $team['assigner']['name'] ?? '',
                            implode(', ', array_column($team['collaborators'], 'name')),
                        ]);
                    }

                    fputcsv($handle, $row);
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

    /** Format exported dates as Thai-time text so Excel does not render narrow date columns as ######. */
    private function csvDateTime($value): string
    {
        return $value
            ? $value->copy()->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('d/m/Y H:i').' น.'
            : '';
    }

    private function statusLabel(int $status): string
    {
        return [
            2 => 'กำลังทำ',
            3 => 'ตรวจสอบ',
            4 => 'เสร็จสิ้น',
            5 => 'พักงานชั่วคราว',
            6 => 'ล่าช้า',
        ][$status] ?? 'สถานะไม่รองรับ';
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
        abort_unless($this->canSeeOrganizationReports(), 403);
    }

    /**
     * เข้าหน้าเลือกรายงานได้หรือไม่ — ทุกคนที่มีรายงานอย่างน้อยหนึ่งชนิด
     */
    private function authorizeAnyReports(): void
    {
        abort_unless(
            $this->canSeeOrganizationReports() || $this->landingCards() !== [],
            403
        );
    }

    /**
     * รายงานระดับองค์กรและรายบุคคลของคนอื่น — admin, หัวหน้าแผนก และ viewer
     */
    private function canSeeOrganizationReports(): bool
    {
        $user = Auth::user();

        return in_array($user?->role, ['admin', 'viewer'], true) || (bool) $user?->isDepartmentHead();
    }

    /**
     * การ์ดที่ผู้ใช้คนนี้เปิดได้จริงบนหน้าเลือกรายงาน
     *
     * @return array<int, array<string, mixed>>
     */
    private function landingCards(): array
    {
        $user = Auth::user();
        $cards = [];

        if ($this->canSeeOrganizationReports()) {
            $cards[] = [
                'tone' => 'organization',
                'icon' => 'bi-bar-chart-line',
                'title' => 'ดูภาพรวมองค์กร',
                'description' => 'ติดตามแนวโน้มและภาพรวมการทำงานของทุกแผนกในช่วงเวลาที่เลือก',
                'features' => ['แนวโน้มงานและสถิติองค์กร', 'ประสิทธิภาพแต่ละแผนก', 'สถานะและความสำคัญของงาน', 'งานที่ต้องติดตาม'],
                'cta' => 'เข้าสู่รายงานภาพรวมองค์กร',
                'route' => route('reports.organization'),
            ];

            $cards[] = [
                'tone' => 'employee',
                'icon' => 'bi-person-lines-fill',
                'title' => 'ดูรายงานรายบุคคล',
                'description' => 'เลือกพนักงานเพื่อดูผลงานจากงานที่รับผิดชอบจริงและตรวจสอบรายละเอียดได้',
                'features' => ['สถิติการทำงานของพนักงาน', 'อัตราส่งงานตรงเวลา', 'งานที่รับผิดชอบ', 'รายละเอียดงานสำหรับตรวจสอบ'],
                'cta' => 'เลือกพนักงานเพื่อดูรายงาน',
                'route' => route('reports.employees.index'),
            ];
        }

        // รายงานของตัวเอง — สำหรับพนักงานทั่วไป
        //
        // หัวหน้าแผนกไม่ได้การ์ดใบนี้โดยตั้งใจ หน้าเลือกของหัวหน้ามีรายงานรายบุคคล
        // อยู่แล้วซึ่งครอบคลุมตัวเองด้วย การเพิ่มอีกใบจะทำให้มีสองทางไปหาข้อมูล
        // ชุดเดียวกัน ซึ่งเป็นอาการเดียวกับที่ทำให้หน้านี้อ่านยากมาตั้งแต่แรก
        if ($user?->role === 'user' && ! $user->isDepartmentHead()) {
            $cards[] = [
                'tone' => 'employee',
                'icon' => 'bi-person-badge',
                'title' => 'รายงานตัวเอง',
                'description' => 'ผลงานจากงานโครงการที่คุณรับผิดชอบ ทั้งงานที่ปิดแล้วและที่ยังค้างอยู่',
                'features' => ['สถิติงานของคุณเอง', 'อัตราส่งงานตรงเวลา', 'งานที่ยังค้าง', 'ดาวน์โหลดเป็นไฟล์ CSV'],
                'cta' => 'เข้าสู่รายงานตัวเอง',
                'route' => route('reports.my'),
            ];
        }

        // รายงานปฏิบัติงาน — พนักงานเห็นของตัวเอง หัวหน้าเห็นทั้งแผนก admin เห็นทุกแผนก
        if ($user !== null && Gate::forUser($user)->allows('viewReport', WorkLog::class)) {
            $isOwnScope = $user->role === 'user' && ! $user->isDepartmentHead();

            $cards[] = [
                'tone' => 'operational',
                'icon' => 'bi-journal-check',
                'title' => 'รายงานปฏิบัติงาน',
                'description' => $isOwnScope
                    ? 'งานประจำที่ต้องเข้าไปตรวจในแต่ละวัน พร้อมสถานะว่าวันนี้ตรวจไปแล้วหรือยัง'
                    : 'สถานะการตรวจงานประจำของวันนี้ และชั่วโมงงานที่ไม่ปรากฏบนบอร์ดโปรเจกต์',
                'features' => $isOwnScope
                    ? ['ตารางงานประจำของวันนี้', 'ตรวจแล้ว / ยังไม่ตรวจ', 'ชั่วโมงงานของคุณเอง', 'ดาวน์โหลดเป็นไฟล์ CSV']
                    : ['ตารางงานประจำของวันนี้ทั้งทีม', 'ตรวจแล้ว / ยังไม่ตรวจ', 'ชั่วโมงงานรายคน', 'เวลาที่ไม่ได้ลงโปรเจกต์'],
                'cta' => 'เข้าสู่รายงานปฏิบัติงาน',
                'route' => route('reports.operational'),
            ];
        }

        return $cards;
    }

    /**
     * สิทธิ์ดูรายงานภาระงานปฏิบัติการ
     *
     * ตั้งใจให้ต่างจาก authorizeAdminReports() ที่เปิดให้ viewer ดูได้
     * เพราะบันทึกงานประจำวันเป็นข้อมูลรายบุคคลที่ละเอียดกว่าภาพรวมองค์กร
     * กติกาจริงอยู่ที่ WorkLogPolicy::viewReport() เพียงที่เดียว
     */
    private function authorizeOperationalReports(): void
    {
        abort_unless(Gate::allows('viewReport', WorkLog::class), 403);
    }

    /**
     * ไฟล์ CSV ของบันทึกงานประจำวัน
     *
     * โครงเดียวกับ downloadJobsCsv() (BOM + fputcsv + streamDownload) แต่หัวคอลัมน์
     * เป็นของงานปฏิบัติการ ไม่ปนกับงานโครงการ
     *
     * @param  Collection<int, WorkLog>  $logs
     */
    private function downloadWorkLogsCsv(Collection $logs, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            // BOM เพื่อให้ Excel บน Windows อ่านภาษาไทยได้ถูกต้อง
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, [
                'วันที่', 'ผู้บันทึก', 'แผนก', 'ประเภท', 'หมวดงาน', 'เรื่อง',
                'เริ่ม', 'สิ้นสุด', 'นาที', 'โปรเจกต์', 'งาน',
            ]);

            $logs->chunk(200)->each(function (Collection $chunk) use ($handle): void {
                foreach ($chunk as $log) {
                    fputcsv($handle, [
                        $log->work_date?->format('Y-m-d'),
                        $log->user?->name,
                        $log->department?->department_name ?? $log->user?->department?->department_name,
                        WorkLogDesign::kind($log->kind)['label'],
                        $log->category?->name,
                        $log->title,
                        // แปลงกลับเป็นเวลาทำการ ไม่ใช่ UTC ที่เก็บในฐานข้อมูล
                        $log->started_at === null ? '' : TodayWorkspace::businessNow($log->started_at)->format('H:i'),
                        $log->ended_at === null ? '' : TodayWorkspace::businessNow($log->ended_at)->format('H:i'),
                        $log->duration_minutes,
                        $log->project?->name,
                        $log->job_id === null ? '' : 'IT-'.$log->job_id,
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function ensureReportableEmployee(User $user): void
    {
        abort_unless($user->role === 'user' && $user->is_active, 404);
        abort_if(Auth::user()?->isDepartmentHead() && ! Auth::user()->overseesDepartment($user->department_id), 403);
    }

    private function forcedDepartmentId(): ?int
    {
        $user = Auth::user();

        return $user?->isDepartmentHead() ? (int) $user->department_id : null;
    }

    /**
     * บังคับให้พนักงานทั่วไปเห็นเฉพาะบันทึกของตัวเองในรายงานปฏิบัติงาน
     *
     * คืน null สำหรับ admin และหัวหน้าแผนก ซึ่งมีขอบเขตกว้างกว่าอยู่แล้ว
     * (หัวหน้าถูกจำกัดด้วย forcedDepartmentId() แทน)
     *
     * ค่านี้ไม่ได้มาจาก request จึงเปลี่ยนไปดูของคนอื่นด้วยการแก้ URL ไม่ได้
     */
    private function forcedOwnerId(): ?int
    {
        $user = Auth::user();

        if ($user === null || $user->role !== 'user' || $user->isDepartmentHead()) {
            return null;
        }

        return (int) $user->id;
    }
}
