<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\DepartmentTaskVisibility;
use App\Support\ReportMetrics;
use App\Support\ReportPeriod;
use App\Support\TaskLink;
use App\Support\WorkBoardDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * ค้นหางานและโปรเจกต์จากช่องค้นหาบน Topbar (layouts/components/global-search)
 *
 * ขอบเขตข้อมูลมาจาก scope และ policy ที่หน้าอื่นใช้อยู่แล้ว ไม่ได้นิยามสิทธิ์ขึ้นใหม่:
 *   งาน      WorkOrder::visibleInProjectsFor() — ชุดเดียวกับหน้า "งานของฉัน"
 *            viewer อ่านได้เฉพาะงานที่อนุมัติแล้ว ตาม WorkOrderPolicy::view() และเปิดผลที่รายงานโปรเจกต์
 *   โปรเจกต์  เจ้าของ / มีงานที่ผู้ใช้ร่วม / แผนกที่ได้รับสิทธิ์ดู ตาม WorkOrderListPolicy::view()
 *
 * ผลลัพธ์ทุกแถวถูกถาม policy view() ซ้ำอีกชั้นก่อนส่งออก ถ้า scope กับ policy เคยตอบไม่ตรงกัน
 * แถวนั้นจะหายไปจากผลค้นหา ไม่ใช่รั่วออกไปให้คนที่ไม่มีสิทธิ์เห็น
 *
 * ค้นจาก: ชื่องาน รายละเอียดงาน ชื่อโปรเจกต์ที่งานสังกัด และชื่อโปรเจกต์
 * (work_order_lists ไม่มีคอลัมน์รายละเอียด จึงค้นได้เฉพาะชื่อ)
 * ไม่ค้นชื่อผู้รับผิดชอบ เพราะผลจะกลายเป็นรายการงานทั้งหมดของคนนั้น ซึ่งหน้า Workspace ทำได้ดีกว่า
 */
class GlobalSearchService
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    public const TASK_LIMIT = 8;

    public const PROJECT_LIMIT = 5;

    /**
     * @return array{
     *     query: string,
     *     tasks: list<array<string, mixed>>,
     *     projects: list<array<string, mixed>>,
     *     more_tasks: bool,
     *     more_projects: bool,
     *     all_results_url: string|null,
     * }
     */
    public function search(User $user, string $term): array
    {
        $term = mb_substr(trim(preg_replace('/\s+/u', ' ', $term) ?? ''), 0, self::MAX_LENGTH);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return $this->result($term, collect(), collect(), false, false, $user);
        }

        // ผู้ใช้พิมพ์ % หรือ _ ได้เป็นตัวอักษรธรรมดา ไม่ใช่ wildcard (แบบเดียวกับ AnnouncementQueryService)
        $escaped = addcslashes($term, '%_\\');
        $like = '%'.$escaped.'%';

        [$tasks, $moreTasks] = $this->visibleFirst($this->taskQuery($user, $like)->get(), $user, self::TASK_LIMIT);
        [$projects, $moreProjects] = $this->visibleFirst($this->projectQuery($user, $like, $escaped.'%')->get(), $user, self::PROJECT_LIMIT);

        return $this->result($term, $tasks, $projects, $moreTasks, $moreProjects, $user);
    }

    private function taskQuery(User $user, string $like): Builder
    {
        return WorkOrder::query()
            // ความสัมพันธ์ที่ทั้งผลลัพธ์และ WorkOrderPolicy::view() / TaskLink อ่าน — โหลดครั้งเดียวทั้งชุด
            ->with(['taskList:id,name,archived_at', 'user:id,role,department_id', 'collaborators'])
            ->when($user->role === 'viewer',
                fn (Builder $query) => $query->where('approval_status', 'approved'),
                fn (Builder $query) => $query->visibleInProjectsFor($user))
            ->where(fn (Builder $match) => $match
                ->where('job_topic', 'like', $like)
                ->orWhere('job_details', 'like', $like)
                ->orWhereHas('taskList', fn (Builder $list) => $list->where('name', 'like', $like)))
            // งานในโปรเจกต์ที่เลื่อนออกไปแล้วไม่อยู่บนบอร์ด ลิงก์เปิดงานจึงหาแถวของมันไม่เจอ
            ->where(fn (Builder $active) => $active
                ->whereNull('work_order_list_id')
                ->orWhereHas('taskList', fn (Builder $list) => $list->whereNull('archived_at')))
            // ชื่องานตรงคำค้นมาก่อนงานที่ตรงเพียงรายละเอียดหรือชื่อโปรเจกต์
            ->orderByRaw('CASE WHEN job_topic LIKE ? THEN 0 ELSE 1 END', [$like])
            ->orderByDesc('updated_at')
            ->orderByDesc('job_id')
            // เผื่อแถวที่ policy ปฏิเสธ และเผื่อหนึ่งแถวเพื่อรู้ว่ายังมีผลเพิ่ม
            ->limit(self::TASK_LIMIT * 2 + 1);
    }

    private function projectQuery(User $user, string $like, string $prefix): Builder
    {
        $grantedDepartmentIds = DepartmentTaskVisibility::departmentIdsFor($user);

        return WorkOrderList::query()
            ->with('user:id,name,department_id')
            ->whereNull('archived_at')
            ->where('name', 'like', $like)
            ->when(! in_array($user->role, ['admin', 'viewer'], true), fn (Builder $query) => $query
                ->where(fn (Builder $visible) => $visible
                    ->where('user_id', $user->id)
                    ->orWhereHas('workOrders', fn (Builder $tasks) => $tasks->involving($user))
                    ->when($grantedDepartmentIds !== [], fn (Builder $granted) => $granted
                        ->orWhereHas('workOrders', fn (Builder $tasks) => $tasks->inDepartments($grantedDepartmentIds)))))
            // ชื่อที่ขึ้นต้นด้วยคำค้นมาก่อนชื่อที่มีคำค้นอยู่กลางคำ
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$prefix])
            ->orderBy('name')
            ->limit(self::PROJECT_LIMIT * 2 + 1);
    }

    /**
     * ถาม policy ทีละแถวแล้วตัดเหลือตามจำนวนที่แสดง
     *
     * @template TModel of WorkOrder|WorkOrderList
     *
     * @param  Collection<int, TModel>  $candidates
     * @return array{0: Collection<int, TModel>, 1: bool}
     */
    private function visibleFirst(Collection $candidates, User $user, int $limit): array
    {
        $gate = Gate::forUser($user);
        $visible = $candidates->filter(fn ($model): bool => $gate->allows('view', $model))->values();

        return [$visible->take($limit), $visible->count() > $limit];
    }

    /**
     * @param  Collection<int, WorkOrder>  $tasks
     * @param  Collection<int, WorkOrderList>  $projects
     */
    private function result(string $term, Collection $tasks, Collection $projects, bool $moreTasks, bool $moreProjects, User $user): array
    {
        return [
            'query' => $term,
            'tasks' => $tasks->map(fn (WorkOrder $task): array => $this->presentTask($task, $user))->all(),
            'projects' => $projects->map(fn (WorkOrderList $project): array => $this->presentProject($project, $user))->all(),
            'more_tasks' => $moreTasks,
            'more_projects' => $moreProjects,
            'all_results_url' => mb_strlen($term) < self::MIN_LENGTH ? null : $this->listUrl($user, $term),
        ];
    }

    private function presentTask(WorkOrder $task, User $user): array
    {
        $status = WorkBoardDesign::statusKey($task);

        return [
            'id' => $task->job_id,
            'type' => 'task',
            'title' => $task->job_topic,
            'project' => $task->taskList?->name,
            // งานที่ยังไม่อนุมัติไม่มีสถานะบนบอร์ด และสถานะที่บอร์ดไม่รองรับก็ไม่ควรแสดงเป็นป้าย
            'status' => $task->approval_status === 'approved' && $status !== 'unsupported'
                ? ['key' => $status, 'label' => WorkBoardDesign::statusMeta($status)['label'], 'tone' => WorkBoardDesign::statusMeta($status)['tone']]
                : null,
            /*
             * viewer ไม่มีหน้ารายละเอียดงานรายใบ (TaskController::show ส่งกลับแดชบอร์ด ซึ่งมีแต่สถิติรายแผนก)
             * จึงพาไปหน้ารายละเอียดของรายงานโปรเจกต์ ที่กรองชื่องานไว้ในวันที่งานถูกสร้าง
             * (รายงานนับงานตามวันที่สร้าง — ProjectReportService::periodJobs)
             */
            'url' => $user->role === 'viewer'
                ? $this->reportUrl($task->job_topic, $task->created_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE))
                : TaskLink::open($task, $user),
        ];
    }

    private function presentProject(WorkOrderList $project, User $user): array
    {
        return [
            'id' => $project->id,
            'type' => 'project',
            'title' => $project->name,
            'owner' => $project->user?->name,
            'url' => $this->listUrl($user, $project->name),
        ];
    }

    /**
     * หน้ารายการที่กรองด้วยคำนี้ ซึ่งค้นทั้งชื่องานและชื่อโปรเจกต์:
     *   ทุกบทบาทที่มี "งานของฉัน"  มุมมองตารางของงานของฉัน (?search=)
     *   viewer                 หน้ารายละเอียดของรายงานโปรเจกต์ (?q=) ย้อนหลังเต็มช่วงที่รายงานยอมรับ
     */
    private function listUrl(User $user, string $search): string
    {
        return $user->role === 'viewer'
            ? $this->reportUrl($search, null)
            : route('mytasks.index', ['view' => 'board', 'search' => $search]);
    }

    /**
     * รายละเอียดรายงานโปรเจกต์ที่กรองด้วยคำค้น — viewer อ่านรายงานระดับองค์กรได้ (ReportController)
     * ไม่ระบุวัน = ย้อนหลัง ReportPeriod::MAX_DAYS วันถึงวันนี้ตามเวลาธุรกิจ
     */
    private function reportUrl(string $search, ?CarbonInterface $day): string
    {
        $today = CarbonImmutable::now(ReportMetrics::BUSINESS_TIMEZONE);

        return route('reports.projects.details', [
            'from' => ($day ?? $today->subDays(ReportPeriod::MAX_DAYS - 1))->format('Y-m-d'),
            'to' => ($day ?? $today)->format('Y-m-d'),
            'q' => $search,
        ]);
    }
}
