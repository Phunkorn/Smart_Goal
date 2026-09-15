<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\OperationalWorkloadReportService;
use App\Services\ProjectReportService;
use App\Support\OperationalReportScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
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

        return view('reports.index', ['cards' => $this->landingCards()]);
    }

    /**
     * รายงานปฏิบัติงานประจำเดือน — หน้า Overview
     *
     * หัวหน้าแผนก/admin ที่ยังไม่เลือกคนเห็นภาพรวมทีมก่อน พนักงานทั่วไปเห็นของตัวเองเสมอ
     *
     * ใช้ตัวตรวจสิทธิ์ของตัวเอง ไม่ใช่ authorizeAdminReports() เพราะรายงานนี้
     * เป็นข้อมูลรายบุคคลที่ละเอียดกว่าภาพรวมองค์กร viewer จึงต้องไม่เห็น
     */
    public function operational(Request $request, OperationalWorkloadReportService $reports)
    {
        $scope = $this->operationalScope($request, $reports, allowTeam: true);

        return $scope->isTeamView()
            ? view('reports.operational.team', $reports->teamOverview($scope))
            : view('reports.operational', $reports->overview($scope));
    }

    public function operationalDaily(Request $request, OperationalWorkloadReportService $reports)
    {
        $scope = $this->operationalScope($request, $reports);

        return view('reports.operational.daily', [
            'scope' => $scope,
            'rows' => $reports->dailyRows($scope),
        ]);
    }

    public function operationalFrequent(Request $request, OperationalWorkloadReportService $reports)
    {
        $scope = $this->operationalScope($request, $reports);

        return view('reports.operational.frequent', [
            'scope' => $scope,
            'rows' => $reports->frequentWork($scope),
        ]);
    }

    public function operationalDelays(Request $request, OperationalWorkloadReportService $reports)
    {
        $scope = $this->operationalScope($request, $reports);

        return view('reports.operational.delays', [
            'scope' => $scope,
            'groups' => $reports->delayReasonGroups($scope),
            'events' => $reports->delayEvents($scope),
        ]);
    }

    /**
     * CSV หนึ่งไฟล์ต่อหนึ่งรายงาน — แถวชุดเดียวกับหน้า detail ของรายงานนั้น
     *
     * $report มาจาก defaults ของ route (daily / frequent / delays) ไม่ใช่จาก query string
     */
    public function exportOperationalCsv(
        Request $request,
        OperationalWorkloadReportService $reports,
        string $report
    ): StreamedResponse {
        $scope = $this->operationalScope($request, $reports);
        $table = $reports->csvTable($report, $scope);

        return $this->downloadTableCsv(
            $table['headers'],
            $table['rows'],
            'smart-goals-operational-'.$report.'-'.$scope->monthKey().'.csv',
        );
    }

    /**
     * รายงานโปรเจกต์ประจำเดือน — ภาพรวมและรายบุคคลในหน้าเดียว
     *
     * หัวหน้าแผนก/admin/viewer เปิดมาเป็นภาพรวมของทุกคนในขอบเขตที่ดูแล แล้วเลือกพนักงาน
     * จากช่องด้านบนเพื่อดูรายบุคคล พนักงานทั่วไปใช้หน้าเดียวกันแต่เห็นเฉพาะงานของตัวเอง
     *
     * สิทธิ์ถูกบังคับที่ projectReportSubjects() ที่เดียว ใช้ร่วมกับหน้ารายละเอียดและ CSV:
     * - พนักงานทั่วไป: รายชื่อมีแค่ตัวเอง และ owner ถูกบังคับเป็นตัวเองเสมอ (แก้ ?owner= ไม่มีผล)
     * - รายชื่อที่เลือกได้ = พนักงาน active ในขอบเขตที่ผู้ดูดูแล (หัวหน้าถูกบังคับแผนก)
     * - owner ที่ไม่อยู่ในรายชื่อ (นอกแผนก ไม่ active admin viewer) ได้ภาพรวมแทน ไม่มีข้อมูลของคนนั้นหลุดออกมา
     */
    public function projects(Request $request, ProjectReportService $reports)
    {
        $this->authorizeProjectReports();

        $data = $this->projectReportData($request, $reports);

        // ตารางหน้าหลักเป็นชุดเดียวกับหน้ารายละเอียด (รวมหลักฐาน) จึงเติมไฟล์ที่ตรวจสิทธิ์แล้วเฉพาะแถวที่แสดง
        return view('reports.projects.index', [
            ...$data,
            'previewRows' => $reports->withEvidence($data['taskRows']->take(ProjectReportService::PREVIEW_LIMIT)->values(), $request->user()),
        ]);
    }

    /**
     * ดูรายละเอียดทั้งหมด — ทุกงานของเดือน (ตามตัวกรองเดียวกับหน้าหลัก) พร้อมงานย่อย
     * ไฟล์แนบ และเวลาเริ่ม/เสร็จ ให้หัวหน้าแผนก/admin ตรวจผลงานจากหลักฐานจริงได้
     *
     * ขอบเขตคนและสิทธิ์ใช้ projectReportSubjects() ชุดเดียวกับหน้าหลักและ CSV
     * ลิงก์ไฟล์ออกให้เฉพาะงานที่ผู้ดูผ่าน WorkOrderPolicy (ดู ProjectReportService::withEvidence)
     */
    public function projectDetails(Request $request, ProjectReportService $reports)
    {
        $this->authorizeProjectReports();

        $data = $this->projectReportData($request, $reports);
        $page = $reports->paginate($data['taskRows'], $request, 'reports.projects.details', $data['query']);
        $page->setCollection($reports->withEvidence($page->getCollection(), $request->user()));

        return view('reports.projects.details', [...$data, 'pageRows' => $page]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectReportData(Request $request, ProjectReportService $reports): array
    {
        $subjects = $this->projectReportSubjects($request);
        $report = $reports->build($request, $subjects['members'], $subjects['owner']);

        // ภาพรวมแผนกที่ admin เลือกต้องติดไปกับทุกลิงก์ต่อจากหน้านี้ (CSV แบ่งหน้า เรียงลำดับ ตัวกรอง)
        // ไม่อย่างนั้นกดเปลี่ยนหน้าแล้วจะหลุดกลับไปเป็นภาพรวมทุกแผนกเงียบ ๆ
        if ($report['isTeamView'] && $subjects['overviewDepartmentId'] !== null) {
            $report['query'] = ['department' => $subjects['overviewDepartmentId'], ...$report['query']];
        }

        return [
            ...$report,
            'owners' => $subjects['owners'],
            'scopeName' => $subjects['scopeName'],
            'overviewLabel' => $subjects['overviewLabel'],
            'isForcedDepartment' => $subjects['isForcedDepartment'],
            'isPersonalReport' => $subjects['isPersonal'],
            'overviewDepartmentId' => $subjects['overviewDepartmentId'],
            'departmentOptions' => $subjects['departmentOptions'],
        ];
    }

    /**
     * CSV ของรายงานโปรเจกต์ — แถวเดียวกับตารางบนหน้าจอ (เดือน คน ตัวกรอง และลำดับเดียวกัน)
     */
    public function exportProjectsCsv(Request $request, ProjectReportService $reports): StreamedResponse
    {
        $this->authorizeProjectReports();

        ['members' => $members, 'owner' => $owner] = $this->projectReportSubjects($request);
        $report = $reports->build($request, $members, $owner);
        $table = $reports->csvTable($report);

        return $this->downloadTableCsv(
            $table['headers'],
            $table['rows'],
            'smart-goals-projects-'.($owner?->id ?? 'team').'-'.$report['periodFileKey'].'.csv',
        );
    }

    /**
     * หน้าเลือกพนักงานเดิม — เลิกใช้แล้ว พาไปรายงานโปรเจกต์ที่เลือกพนักงานได้ในหน้าเดียว
     *
     * ยังตรวจสิทธิ์เดิมก่อน redirect เพื่อให้ลิงก์เก่าที่บันทึกไว้ได้ผลเท่าเดิมทุกบทบาท
     */
    public function employees(Request $request)
    {
        $this->authorizeAdminReports();

        return redirect()->route('reports.projects', array_filter([
            'department' => $this->forcedDepartmentId() === null ? $request->integer('department') : null,
        ]));
    }

    /**
     * รายงานรายบุคคลเดิม — ย้ายไปเป็นโหมดรายบุคคลของรายงานโปรเจกต์
     *
     * ตรวจสิทธิ์เดิมก่อน redirect: คนนอกแผนกของหัวหน้ายังได้ 403 และคนที่ไม่ใช่พนักงาน active ได้ 404
     */
    public function employeeReport(Request $request, User $user)
    {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        return redirect()->route('reports.projects', [
            ...$request->except(['owner', 'department']),
            'owner' => $user->id,
        ]);
    }

    /**
     * CSV รายบุคคลเดิม — ตรวจสิทธิ์เดิมแล้วส่งต่อไป CSV ของรายงานโปรเจกต์
     */
    public function exportEmployeeCsv(Request $request, User $user)
    {
        $this->authorizeAdminReports();
        $this->ensureReportableEmployee($user);

        return redirect()->route('reports.projects.csv', array_filter([
            'owner' => $user->id,
            'month' => $request->string('month')->toString() ?: null,
        ]));
    }

    private function authorizeAdminReports(): void
    {
        abort_unless($this->canSeeOrganizationReports(), 403);
    }

    /**
     * รายงานโปรเจกต์ — admin/หัวหน้าแผนก/viewer ในขอบเขตที่ดูแล และพนักงานทั่วไปเฉพาะของตัวเอง
     */
    private function authorizeProjectReports(): void
    {
        abort_unless($this->canSeeOrganizationReports() || $this->forcedOwnerId() !== null, 403);
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

        // รายงานภาพรวมองค์กรถูกยกเลิกแล้ว ภาพรวมแผนกอยู่ในรายงานโปรเจกต์ (เลือก "ภาพรวม" ในช่องพนักงาน)
        if ($this->canSeeOrganizationReports()) {
            // รายงานโปรเจกต์ — เข้าภาพรวมทันที แล้วเลือกพนักงานได้ในหน้าเดียว ไม่ผ่านหน้าเลือกพนักงาน
            $cards[] = [
                'tone' => 'employee',
                'icon' => 'bi-kanban',
                'title' => 'รายงานโปรเจกต์',
                'description' => 'สรุปโปรเจกต์รายเดือนในขอบเขตที่ดูแล และเลือกดูรายงานของพนักงานแต่ละคนได้ในหน้าเดียว',
                'features' => ['สรุปโปรเจกต์และงานประจำเดือน', 'เลือกพนักงานจากหน้าเดียวกัน', 'งานข้ามแผนกและบทบาทในงาน', 'ตัวกรองและไฟล์ CSV'],
                'cta' => 'เข้าสู่รายงานโปรเจกต์',
                'route' => route('reports.projects'),
            ];
        }

        // รายงานโปรเจกต์ของตัวเอง — พนักงานทั่วไปใช้หน้าเดียวกับหัวหน้า แต่ขอบเขตเฉพาะตัวเอง
        //
        // หัวหน้าแผนกไม่ได้การ์ดใบนี้โดยตั้งใจ รายงานโปรเจกต์ของหัวหน้าเลือกดูตัวเองได้อยู่แล้ว
        if ($this->forcedOwnerId() !== null) {
            $cards[] = [
                'tone' => 'employee',
                'icon' => 'bi-kanban',
                'title' => 'รายงานโปรเจกต์',
                'description' => 'สรุปโปรเจกต์รายเดือนที่คุณมีส่วนร่วม ทั้งงานที่รับผิดชอบและงานที่ไปร่วมกับคนอื่น',
                'features' => ['สรุปโปรเจกต์และงานประจำเดือน', 'แนวโน้มงานและสถานะงาน', 'งานข้ามแผนกและบทบาทในงาน', 'ตัวกรองและไฟล์ CSV'],
                'cta' => 'เข้าสู่รายงานโปรเจกต์',
                'route' => route('reports.projects'),
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
                    ? 'สรุปงานประจำและงานนอกสถานที่ของคุณรายเดือน พร้อมชั่วโมงงานและเหตุผลที่ล่าช้า'
                    : 'รายงานปฏิบัติงานรายบุคคลรายเดือน ดูของตัวเองหรือเลือกพนักงานในขอบเขตที่ดูแล',
                'features' => $isOwnScope
                    ? ['ชั่วโมงงานรายวัน', 'สัดส่วนประเภทงาน', 'งานที่ทำบ่อยและเหตุผลที่ล่าช้า', 'CSV แยกตามรายงาน']
                    : ['เลือกพนักงานในขอบเขตที่ดูแล', 'ชั่วโมงงานและสัดส่วนประเภทงาน', 'งานที่ทำบ่อยและเหตุผลที่ล่าช้า', 'CSV แยกตามรายงาน'],
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
     * ขอบเขตของรายงานปฏิบัติงาน — ตรวจสิทธิ์และตัดสินเดือน/คนที่เดียวสำหรับทุก route
     */
    private function operationalScope(
        Request $request,
        OperationalWorkloadReportService $reports,
        bool $allowTeam = false
    ): OperationalReportScope {
        $this->authorizeOperationalReports();

        return $reports->scope($request, $request->user(), $this->forcedDepartmentId(), $this->forcedOwnerId(), $allowTeam);
    }

    /**
     * ไฟล์ CSV ของตารางหนึ่งตาราง
     *
     * BOM + fputcsv + streamDownload
     * ค่าที่ขึ้นต้นด้วย = + - @ ถูกนำหน้าด้วย ' เพื่อไม่ให้ Excel ตีความชื่องานหรือเหตุผล
     * ที่ผู้ใช้พิมพ์เองเป็นสูตร
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, int|string|null>>  $rows
     */
    private function downloadTableCsv(array $headers, array $rows, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            // BOM เพื่อให้ Excel บน Windows อ่านภาษาไทยได้ถูกต้อง
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value,
                    $row
                ));
            }

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * คนที่ผู้ดูเลือกดูในรายงานโปรเจกต์ได้ และคนที่ถูกเลือกอยู่
     *
     * หัวหน้าแผนกได้เฉพาะพนักงาน active ในแผนกตัวเอง admin/viewer ได้พนักงาน active ทุกแผนก
     * owner มาจากรายชื่อนี้เท่านั้น ค่าที่ไม่อยู่ในรายชื่อจึงกลายเป็นภาพรวม
     *
     * admin/viewer เลือกภาพรวมทีละแผนกได้ (overviewDepartmentId) โดยรายชื่อในดร็อปดาวน์ (owners) ยังเป็นทุกคน
     * ส่วน members คือคนที่นับในภาพรวมจริง — แคบลงเหลือแผนกนั้นเมื่อเลือกภาพรวมแผนก
     *
     * @return array{members: Collection<int, User>, owners: Collection<int, User>, owner: User|null, scopeName: string, overviewLabel: string, isForcedDepartment: bool, isPersonal: bool, overviewDepartmentId: int|null, departmentOptions: array<int, string>}
     */
    private function projectReportSubjects(Request $request): array
    {
        // พนักงานทั่วไป: ขอบเขตคือตัวเองคนเดียว ไม่อ่าน ?owner= หรือ ?department= จาก request เลย
        if ($this->forcedOwnerId() !== null) {
            $self = User::query()
                ->with('department:id,department_name')
                ->whereKey($this->forcedOwnerId())
                ->firstOrFail(['id', 'name', 'role', 'is_active', 'department_id', 'profile_image']);

            return [
                'members' => collect([$self]),
                'owners' => collect([$self]),
                'owner' => $self,
                'scopeName' => 'ของฉัน',
                'overviewLabel' => $self->name,
                'isForcedDepartment' => true,
                'isPersonal' => true,
                'overviewDepartmentId' => null,
                'departmentOptions' => [],
            ];
        }

        $forcedDepartmentId = $this->forcedDepartmentId();
        $people = User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->where('department_id', $id))
            ->orderBy('name')
            // role และ is_active ต้องถูกโหลดมาด้วย ensureReportableEmployee() อ่านสองคอลัมน์นี้ตรวจซ้ำ
            ->get(['id', 'name', 'role', 'is_active', 'department_id', 'profile_image']);
        $owner = $people->firstWhere('id', $request->integer('owner'));

        if ($owner !== null) {
            // รายชื่อถูกกรองด้วยกติกาเดียวกันอยู่แล้ว ตรวจซ้ำอีกชั้นเพื่อไม่ให้การแก้ query ในอนาคตเปิดช่องรั่ว
            $this->ensureReportableEmployee($owner);
        }

        // ภาพรวมทีละแผนก — เฉพาะ admin/viewer หัวหน้าแผนกถูกล็อกแผนกตัวเองอยู่แล้วจึงไม่มีตัวเลือกนี้
        // ตัวเลือกคือแผนกที่มีพนักงาน active อยู่จริง แผนกที่ส่งมานอกรายการถูกทิ้งแล้วได้ภาพรวมทุกแผนก
        $departmentOptions = $forcedDepartmentId === null
            ? Department::query()
                ->whereIn('id', $people->pluck('department_id')->filter()->unique()->values())
                ->orderBy('department_name')
                ->pluck('department_name', 'id')
                ->all()
            : [];
        $requestedDepartment = $this->requestedOverviewDepartment($request);
        $overviewDepartmentId = $owner === null
            && $requestedDepartment !== null
            && array_key_exists($requestedDepartment, $departmentOptions)
                ? $requestedDepartment
                : null;

        $members = $overviewDepartmentId === null
            ? $people
            : $people->where('department_id', $overviewDepartmentId)->values();

        $departmentId = $forcedDepartmentId ?? $overviewDepartmentId;
        $departmentName = match (true) {
            $departmentId === null => null,
            array_key_exists($departmentId, $departmentOptions) => $departmentOptions[$departmentId],
            default => Department::query()->whereKey($departmentId)->value('department_name'),
        };
        $scopeName = $departmentName === null ? 'ทุกแผนก' : 'แผนก '.$departmentName;

        return [
            'members' => $members,
            'owners' => $people,
            'owner' => $owner,
            'scopeName' => $scopeName,
            'overviewLabel' => 'ภาพรวม'.$scopeName,
            'isForcedDepartment' => $forcedDepartmentId !== null,
            'isPersonal' => false,
            'overviewDepartmentId' => $overviewDepartmentId,
            'departmentOptions' => $departmentOptions,
        ];
    }

    /**
     * แผนกของภาพรวมที่ขอมา — จากดร็อปดาวน์ (owner=department-5) หรือลิงก์ที่พกต่อมา (department=5)
     *
     * ดร็อปดาวน์ใช้ช่อง owner ช่องเดียวทั้งภาพรวมแผนกและรายบุคคล จึงเข้ารหัสแผนกไว้ในค่าของ owner
     * ส่วนลิงก์ภายในหน้าใช้ department=ID ซึ่งตรงกับลิงก์เดิมของหน้าเลือกพนักงาน (employees())
     * ค่านี้ยังไม่ผ่านการตรวจ ผู้เรียกต้องเทียบกับรายการแผนกที่อนุญาตเสมอ
     */
    private function requestedOverviewDepartment(Request $request): ?int
    {
        $owner = $request->query('owner');

        if (is_string($owner) && preg_match('/^department-(\d+)$/', $owner, $matches) === 1) {
            return (int) $matches[1];
        }

        $department = $request->query('department');

        return is_scalar($department) && ctype_digit((string) $department) ? (int) $department : null;
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
     * บังคับให้พนักงานทั่วไปเห็นเฉพาะข้อมูลของตัวเอง (รายงานปฏิบัติงานและรายงานโปรเจกต์)
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
