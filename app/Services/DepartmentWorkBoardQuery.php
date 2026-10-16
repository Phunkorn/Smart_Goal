<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\ProjectRoles;
use App\Support\ReportMetrics;
use App\Support\TodayWorkspace;
use App\Support\WorkBoardDesign;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DepartmentWorkBoardQuery
{
    private const DONE_STATUS = 4;

    /** ล่าช้าก่อน แล้วครบกำหนดวันนี้ ตามด้วยงานที่กำลังทำอยู่ และปิดท้ายด้วยงานที่เพิ่งเริ่มวันนี้ */
    private const TODAY_BUCKET_ORDER = ['late' => 0, 'due_today' => 1, 'active' => 2, 'starts_today' => 3];

    public function __construct(
        private readonly MemberWorkloadQuery $memberWorkloads,
        private readonly TodayOperationalStatus $todayOperations,
    ) {}

    /**
     * สถานะงานประจำ/นอกสถานที่ของวันนี้ — เฉพาะคนที่ผู้ดูมีสิทธิ์เห็นบันทึกงานประจำวัน
     *
     * สิทธิ์ใช้ WorkLogPolicy::viewDay ตัวเดียวกับหน้าบันทึกงาน (ตัวเอง / หัวหน้าแผนกนั้น / admin)
     * ผู้ใช้ทั่วไปที่เปิดบอร์ดของทีมจึงไม่เห็นว่าเพื่อนทำอะไร
     *
     * @param  Collection<int, User>  $members
     * @return Collection<int, array<string, mixed>> keyed by user id
     */
    public function todayOperations(Collection $members, ?User $viewer): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        $visible = $members->filter(fn (User $member): bool => Gate::forUser($viewer)
            ->allows('viewDay', [WorkLog::class, $member]))->values();

        return $this->todayOperations->forMembers($visible);
    }

    /**
     * สถานะวันนี้ของสมาชิกหนึ่งคน พร้อมรายการ สำหรับแท็บ "งานปฏิบัติ" ในแผง Preview — null เมื่อไม่มีสิทธิ์
     *
     * สองระดับ ตัดสินที่ WorkLogPolicy:
     *   viewDay           ตัวเอง / หัวหน้าแผนกนั้น / admin — เห็นครบทุกช่องเหมือนหน้าบันทึกงาน
     *   viewTodaySummary  พนักงานทุกคนทุกแผนก — ภาพรวม (ชื่องาน ประเภท หมวด เวลา สถานะ)
     *                     ตัดสถานที่ออก เพราะเป็นรายละเอียดระดับหน้าบันทึกงาน
     *
     * การ์ดสมาชิกในหน้ารายชื่อแผนก (todayOperations()) ยังใช้ viewDay อย่างเดียวตามเดิม
     *
     * @return array<string, mixed>|null
     */
    public function memberOperations(User $member, ?User $viewer): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $gate = Gate::forUser($viewer);
        $fullDetail = $gate->allows('viewDay', [WorkLog::class, $member]);

        if (! $fullDetail && ! $gate->allows('viewTodaySummary', [WorkLog::class, $member])) {
            return null;
        }

        $summary = $this->todayOperations->forMembers(collect([$member]))->get((int) $member->id);

        if ($summary === null) {
            return null;
        }

        $items = $this->todayOperations->items($summary);

        if (! $fullDetail) {
            $items = array_map(fn (array $item): array => [...$item, 'location' => null], $items);
        }

        return [...$summary, 'items' => $items];
    }

    /**
     * Build the lightweight member directory without loading every task model.
     *
     * @return array{members: Collection<int, User>, projects: Collection<int, WorkOrderList>}
     */
    public function directory(Department $department, Request $request, bool $isAdmin): array
    {
        $status = $request->string('status')->toString();
        $projectId = $request->integer('project_id');
        $search = trim($request->string('search')->toString());
        $hasTaskFilter = ($status !== '' && isset(WorkBoardDesign::STATUSES[$status])) || $projectId > 0;

        $members = User::query()
            // is_department_head ต้องถูกดึงมาด้วย ไม่งั้น RoleLabel::for() บนการ์ดสมาชิก
            // จะอ่านค่าเป็น null แล้วแสดงหัวหน้าแผนกเป็น "พนักงาน" เงียบ ๆ
            ->select(['id', 'name', 'username', 'email', 'profile_image', 'department_id', 'role', 'is_active', 'is_department_head'])
            ->where('department_id', $department->id)
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        $memberIds = $members->pluck('id');
        $taskSummary = collect();
        $updateSummary = collect();

        if ($memberIds->isNotEmpty()) {
            $tasks = DB::query()
                ->fromSub($this->memberWorkloads->memberships($memberIds), 'member_workloads')
                ->join('work_orders', 'work_orders.job_id', '=', 'member_workloads.work_order_id')
                ->whereNull('work_orders.deleted_at')
                ->when(! $isAdmin, fn ($query) => $query->where('work_orders.approval_status', 'approved'));
            $this->applyTaskFilters($tasks, $status, $projectId, 'work_orders.');

            $taskSummary = $tasks
                ->select('member_workloads.member_id')
                ->selectRaw('COUNT(*) as task_count')
                ->selectRaw('MAX(work_orders.updated_at) as latest_task_updated_at')
                ->groupBy('member_workloads.member_id')
                ->get()
                ->keyBy('member_id');

            $updates = DB::table('work_order_updates')
                ->join('work_orders', 'work_orders.job_id', '=', 'work_order_updates.work_order_id')
                ->joinSub($this->memberWorkloads->memberships($memberIds), 'member_workloads', fn ($join) => $join
                    ->on('member_workloads.work_order_id', '=', 'work_orders.job_id'))
                ->whereNull('work_orders.deleted_at')
                ->whereIn('member_workloads.member_id', $memberIds);

            if (! $isAdmin) {
                $updates->where('work_orders.approval_status', 'approved');
            }

            $this->applyTaskFilters($updates, $status, $projectId, 'work_orders.');

            $updateSummary = $updates
                ->select('member_workloads.member_id')
                ->selectRaw('MAX(work_order_updates.created_at) as latest_update_at')
                ->groupBy('member_workloads.member_id')
                ->get()
                ->keyBy('member_id');
        }

        $members = $members
            ->map(function (User $member) use ($taskSummary, $updateSummary): User {
                $tasks = $taskSummary->get($member->id);
                $updates = $updateSummary->get($member->id);
                $latestActivity = collect([
                    $tasks?->latest_task_updated_at,
                    $updates?->latest_update_at,
                ])->filter()->map(fn ($value) => Carbon::parse($value))->sortDesc()->first();

                $member->setAttribute('board_task_count', (int) ($tasks?->task_count ?? 0));
                $member->setAttribute('latest_activity_at', $latestActivity);

                return $member;
            })
            ->when($hasTaskFilter, fn (Collection $collection) => $collection
                ->filter(fn (User $member) => $member->board_task_count > 0))
            ->values();

        $operations = $this->todayOperations($members, $request->user());
        $members->each(fn (User $member) => $member->setAttribute('board_operations', $operations->get((int) $member->id)));

        $allMemberIds = User::query()
            ->where('department_id', $department->id)
            ->where('role', 'user')
            ->where('is_active', true)
            ->pluck('id');
        $directTaskIds = DB::query()
            ->fromSub($this->memberWorkloads->memberships($allMemberIds), 'member_workloads')
            ->select('member_workloads.work_order_id');
        $projectTaskIds = WorkOrder::query()
            ->whereIn('job_id', $directTaskIds)
            ->when(! $isAdmin, fn (Builder $query) => $query->where('approval_status', 'approved'))
            ->whereNotNull('work_order_list_id')
            ->select('work_order_list_id')
            ->distinct();

        $projects = WorkOrderList::query()
            ->whereIn('id', $projectTaskIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return compact('members', 'projects');
    }

    /**
     * ข้อมูลทั้งหมดของแผง Preview สมาชิกหนึ่งคน
     *
     *   tasks                 — งานที่ "ต้องจัดการวันนี้" (นิยามของ TodayWorkspace::tasks())
     *   createdTodayProjects  — โปรเจกต์ที่สร้างวันนี้ รวมโปรเจกต์ที่ยังไม่มีงาน
     *   createdTodayTasks     — งานที่สร้างวันนี้แต่ไม่อยู่ในรายการวันนี้ (เช่นวันเริ่มอยู่ในอนาคต)
     *   allTasks              — งานทั้งหมดของสมาชิก: ที่รับผิดชอบหรือร่วมทำ (ภาระงานตรง) และที่เปิดเอง
     *                           (created_by) รวมงานที่ปิดแล้ว — เฉพาะใบที่ผู้ดูมีสิทธิ์ดู
     *   summary               — ตัวเลขสรุป งานทั้งหมด/กำลังทำ/ใกล้ครบกำหนด/เสร็จสิ้น นับจาก allTasks
     *   roleCounts            — สมาชิกเป็นหัวหน้าโปรเจกต์/ผู้เข้าร่วมกี่งาน นับจากงานที่ยังไม่ปิดใน allTasks
     *
     * "วันนี้" และ "สร้างวันนี้" ตั้งต้นจากภาระงานตรงของสมาชิก (งานระดับบนสุด อนุมัติแล้ว) และไม่ซ้ำกัน
     * ส่วน "งานทั้งหมด" เป็นภาพรวมทุกใบ จึงครอบสองแท็บนั้นโดยตั้งใจ
     *
     * "วันนี้" ของการสร้างคือวันทำงานไทย (TodayWorkspace::businessDayBounds) ไม่ใช่วันของ UTC
     *
     * @return array{tasks: Collection<int, WorkOrder>, allTasks: Collection<int, WorkOrder>, createdTodayProjects: Collection<int, WorkOrderList>, createdTodayTasks: Collection<int, WorkOrder>, summary: array{total: int, active: int, dueSoon: int, done: int}, roleCounts: array{leader: int, participant: int, basis: int}}
     */
    public function memberPreview(User $member, ?User $viewer): array
    {
        $candidates = $this->previewCandidates($member);
        $tasks = $this->todayTasks($candidates);
        $todayIds = $tasks->pluck('job_id')->map(fn ($id) => (int) $id)->all();

        [$dayStart, $dayEnd] = TodayWorkspace::businessDayBounds();
        $createdToday = fn ($createdAt): bool => $createdAt !== null && $createdAt->betweenIncluded($dayStart, $dayEnd);

        $createdTodayTasks = $candidates
            ->filter(fn (WorkOrder $task) => $createdToday($task->created_at)
                && (int) $task->job_status !== self::DONE_STATUS
                && ! in_array((int) $task->job_id, $todayIds, true))
            ->sortBy(fn (WorkOrder $task) => [$task->job_start_at?->getTimestamp() ?? PHP_INT_MAX, $task->job_id])
            ->values();

        $shown = $tasks->concat($createdTodayTasks);

        /*
         * ทุกอย่างที่เกินกว่า "หัวข้องานวันนี้" — รายชื่อทีม งานทั้งหมด และตัวเลขสรุป —
         * มาจากงานที่ผู้ดูมีสิทธิ์ดูจริงตาม WorkOrderPolicy::view() เท่านั้น ไม่ว่าผู้ดูจะมี role
         * หรือ visibility scope แบบใด (Can See ≠ Can Edit — ที่นี่ไม่มีสิทธิ์เขียนใดถูกถาม)
         *
         * Preview แสดงหัวข้อ สถานะ และกำหนดส่งของงานวันนี้ให้เพื่อนร่วมงานทุกคนเห็นอยู่แล้ว
         * (กติกาเดิม) ส่วนที่เพิ่มเข้ามาต้องไม่เปิดอะไรให้คนที่เปิดงานใบนั้นไม่ได้
         */
        // งานทั้งหมด = ภาระงานตรง + งานที่สมาชิกเปิดเอง (instance เดียวกับการ์ดวันนี้ ไม่โหลดซ้ำ)
        $directIds = $candidates->pluck('job_id')->map(fn ($id) => (int) $id)->all();
        $everyTask = $candidates
            ->concat($this->createdByMember($member)
                ->reject(fn (WorkOrder $task) => in_array((int) $task->job_id, $directIds, true)))
            ->values();

        $viewableIds = $this->viewableTaskIds($everyTask, $viewer);
        $everyTask->each(fn (WorkOrder $task) => $task->setAttribute(
            'viewer_can_view',
            isset($viewableIds[(int) $task->job_id])
        ));
        $viewable = $everyTask->filter(fn (WorkOrder $task) => $task->viewer_can_view)->values();
        $openViewable = $viewable->reject(fn (WorkOrder $task) => (int) $task->job_status === self::DONE_STATUS)->values();

        return [
            'tasks' => $tasks,
            'allTasks' => $this->allTasks($viewable),
            'createdTodayProjects' => $this->createdTodayProjects($member, $viewer, $candidates, $shown, $dayStart, $dayEnd),
            'createdTodayTasks' => $createdTodayTasks,
            /*
             * ตัวเลขสรุปของแผง — ใช้นิยามที่มีอยู่แล้วทั้งหมด ไม่สร้างใหม่
             *   active   สถานะกำลังทำหรือรอตรวจสอบ (WorkBoardDesign::statusKey)
             *   dueSoon  ReportMetrics::isDueSoon() — ภายใน 3 วันตามเวลาไทย และยังไม่ล่าช้า
             */
            'summary' => [
                'total' => $viewable->count(),
                'active' => $viewable->filter(fn (WorkOrder $task) => in_array(WorkBoardDesign::statusKey($task), ['doing', 'review'], true))->count(),
                'dueSoon' => $viewable->filter(fn (WorkOrder $task) => (int) $task->job_status !== self::DONE_STATUS && ReportMetrics::isDueSoon($task))->count(),
                'done' => $viewable->filter(fn (WorkOrder $task) => (int) $task->job_status === self::DONE_STATUS)->count(),
            ],
            // บทบาทของสมาชิกในงานที่ยังไม่ปิดและผู้ดูเห็นได้ทั้งหมด — ไม่ผูกกับแท็บใดแท็บหนึ่ง
            'roleCounts' => [
                'leader' => $openViewable->filter(fn (WorkOrder $task) => ProjectRoles::isLeader($task, $member))->count(),
                'participant' => $openViewable->reject(fn (WorkOrder $task) => ProjectRoles::isLeader($task, $member))->count(),
                'basis' => $openViewable->count(),
            ],
        ];
    }

    /**
     * รหัสงานในชุดตั้งต้นที่ผู้ดูมีสิทธิ์ดู — ผลเท่ากับถาม WorkOrderPolicy::view() ทีละใบ
     *
     * ถามด้วย WorkOrder::scopeVisibleInProjectsFor() คิวรีเดียว (เกณฑ์ตรงกับ policy สำหรับงานที่
     * อนุมัติแล้ว) แทนการเรียก policy หลายร้อยครั้งต่อการเปิดแผงหนึ่งครั้ง ข้อยกเว้นเดียวคือหัวหน้า
     * แผนกที่เห็นงานข้ามแผนกของลูกทีมผ่าน WorkOrderPolicy::overseesMemberOnTask() ซึ่ง scope
     * ไม่ครอบคลุม — งานที่เหลือจึงถาม policy ตรง ๆ เฉพาะกรณีนั้น
     *
     * @param  Collection<int, WorkOrder>  $candidates
     * @return array<int, true>
     */
    private function viewableTaskIds(Collection $candidates, ?User $viewer): array
    {
        if ($viewer === null || $candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('job_id')->map(fn ($id) => (int) $id);

        if ($viewer->role === 'admin') {
            return $ids->flip()->map(fn () => true)->all();
        }

        $visible = [];
        foreach ($ids->chunk(500) as $chunk) {
            WorkOrder::query()
                ->visibleInProjectsFor($viewer)
                ->whereIn('job_id', $chunk->all())
                ->pluck('job_id')
                ->each(function ($id) use (&$visible): void {
                    $visible[(int) $id] = true;
                });
        }

        if ($viewer->isDepartmentHead()) {
            $candidates
                ->reject(fn (WorkOrder $task) => isset($visible[(int) $task->job_id]))
                ->filter(fn (WorkOrder $task) => $viewer->can('view', $task))
                ->each(function (WorkOrder $task) use (&$visible): void {
                    $visible[(int) $task->job_id] = true;
                });
        }

        return $visible;
    }

    /**
     * "งานทั้งหมด" — งานทุกใบของสมาชิกที่ผู้ดูเห็นได้ เรียงสิ่งที่ต้องตามก่อน แล้วจึงงานที่ปิดแล้ว
     *
     * ยังไม่ปิด: ล่าช้า → ครบกำหนดวันนี้ → กำลังดำเนินการ → ยังไม่ถึงวันเริ่ม (แต่ละกลุ่มเรียงตามกำหนดส่ง)
     * ปิดแล้ว: ปิดล่าสุดก่อน แต่ละใบติด all_bucket ไว้ให้ Blade เลือกป้ายวันที่
     *
     * @param  Collection<int, WorkOrder>  $viewable
     * @return Collection<int, WorkOrder>
     */
    private function allTasks(Collection $viewable): Collection
    {
        $today = TodayWorkspace::calendarDate(now());
        $order = ['late' => 0, 'due_today' => 1, 'open' => 2, 'not_started' => 3, 'done' => 4];

        return $viewable
            ->each(fn (WorkOrder $task) => $task->setAttribute('all_bucket', match (true) {
                (int) $task->job_status === self::DONE_STATUS => 'done',
                WorkBoardDesign::statusKey($task) === 'late' => 'late',
                TodayWorkspace::calendarDate($task->job_due_at) === $today => 'due_today',
                TodayWorkspace::calendarDate($task->job_start_at) > $today => 'not_started',
                default => 'open',
            }))
            ->sortBy(fn (WorkOrder $task) => [
                $order[$task->all_bucket],
                $task->all_bucket === 'done'
                    ? -($task->job_completed_at?->getTimestamp() ?? 0)
                    : ($task->job_due_at?->getTimestamp() ?? PHP_INT_MAX),
                $task->job_id,
            ])
            ->values();
    }

    /**
     * ภาระงานตรงของสมาชิกระดับบนสุดที่อนุมัติแล้ว — ชุดตั้งต้นของแท็บวันนี้และสร้างวันนี้
     *
     * @return Collection<int, WorkOrder>
     */
    private function previewCandidates(User $member): Collection
    {
        return $this->loadPreviewTasks($this->memberWorkloads->forMember($member));
    }

    /**
     * งานระดับบนสุดที่อนุมัติแล้วซึ่งสมาชิกเป็นผู้เปิด (created_by) — ส่วนที่ "งานทั้งหมด" เพิ่มจากภาระงานตรง
     *
     * @return Collection<int, WorkOrder>
     */
    private function createdByMember(User $member): Collection
    {
        return $this->loadPreviewTasks(WorkOrder::query()->where('created_by', $member->id));
    }

    /**
     * โหลดงานสำหรับ Preview ด้วยคอลัมน์และ relation ชุดเดียวกันทุกหมวด
     *
     * @return Collection<int, WorkOrder>
     */
    private function loadPreviewTasks(Builder $memberTasks): Collection
    {
        // ต้องปรับสถานะล่าช้าให้เป็นปัจจุบันก่อน เหมือนที่ adminMember() และ MyTaskController::index() ทำ
        // มิฉะนั้นงานที่เลยกำหนดจะยังเป็น status 2 แล้วตกช่วง active range ของ TodayWorkspace
        // ทำให้งานล่าช้าหายไปจาก Preview ทั้งที่เป็นงานที่ต้องติดตามที่สุด
        TodayWorkspace::synchronizeLate($memberTasks);

        // คอลัมน์ต้องระบุตาราง เพราะ collaborators join ตาราง pivot ที่มีคอลัมน์ id ของตัวเอง
        $person = fn ($query) => $query->select(['users.id', 'users.name', 'users.profile_image', 'users.department_id', 'users.role', 'users.is_department_head', 'users.is_active']);

        return $memberTasks
            // Preview แสดงงานระดับบนสุด งานย่อยถูกดูจากในงานแม่เท่านั้น
            ->topLevel()
            ->where('approval_status', 'approved')
            ->select([
                'job_id',
                'user_id',
                'created_by',
                'leader_user_id',
                'department_id',
                'work_order_list_id',
                'job_topic',
                'job_priority',
                'job_status',
                'approval_status',
                'job_start_at',
                'job_due_at',
                'job_completed_at',
                'created_at',
            ])
            ->with([
                'taskList:id,name',
                // ProjectRoles::leader()/participants() อ่าน relation ที่โหลดมาแล้วก่อนเสมอ
                // ถ้าไม่โหลดที่นี่ ทุกการ์ดจะยิงคิวรีของตัวเองใน Blade
                'creator' => $person,
                'leader' => $person,
                'user' => $person,
                'collaborators' => $person,
            ])
            ->get();
    }

    /**
     * งานที่สมาชิก "ต้องจัดการวันนี้" สำหรับ Preview แบบ offcanvas
     *
     * สมาชิกภาพของรายการมาจาก TodayWorkspace::tasks() ตัวเดียวกับหน้า "งานของฉัน"
     * และ Member Workspace เต็ม — ห้ามนิยาม "งานวันนี้" ขึ้นใหม่ที่นี่
     *
     * Preview เพิ่มข้อจำกัดของตัวเองสองข้อเท่านั้น:
     *   1) เอาเฉพาะงานที่อนุมัติแล้ว งานที่รออนุมัติเป็นเรื่องของหน้า "คำขออนุมัติ"
     *      และไม่เคยเข้าวงจร auto-start/auto-late จึงไม่ควรปนอยู่ในรายการของวันนี้
     *   2) ตัดงานที่เสร็จแล้วออกทั้งหมด เพราะ Preview ตอบว่า "วันนี้ต้องทำอะไร"
     *      ไม่ใช่ประวัติงาน ส่วนงานที่เสร็จแล้วยังดูได้ครบใน Member Workspace เต็ม
     *
     * @param  Collection<int, WorkOrder>  $candidates
     * @return Collection<int, WorkOrder>
     */
    private function todayTasks(Collection $candidates): Collection
    {
        return TodayWorkspace::tasks($candidates)
            ->reject(fn (WorkOrder $task) => (int) $task->job_status === self::DONE_STATUS)
            ->each(fn (WorkOrder $task) => $task->setAttribute('today_bucket', $this->todayBucket($task)))
            ->sortBy(fn (WorkOrder $task) => [
                self::TODAY_BUCKET_ORDER[$task->today_bucket],
                $task->job_due_at?->getTimestamp() ?? PHP_INT_MAX,
                $task->job_id,
            ])
            ->values();
    }

    /**
     * โปรเจกต์ที่สร้างวันนี้ซึ่งเกี่ยวกับสมาชิกคนนี้ — เป็นเจ้าของ หรือมีงานของเขาอยู่ข้างใน
     *
     * โปรเจกต์ที่ยังไม่มีงานเลยก็ต้องขึ้น เพราะนั่นคือสิ่งที่เพิ่งเกิดขึ้นวันนี้จริง ๆ
     *
     * ผู้ดูเห็นโปรเจกต์ได้เมื่อ WorkOrderListPolicy::view() อนุญาต หรือมีงานในโปรเจกต์นั้น
     * แสดงอยู่ในแผงนี้แล้ว (ชื่อโปรเจกต์ขึ้นบนการ์ดงานอยู่แล้ว ไม่ใช่ข้อมูลใหม่)
     *
     * ตัวเลขบนการ์ดนับเฉพาะงานที่แสดงอยู่ในแผงนี้ ไม่ใช่งานทั้งโปรเจกต์ เพื่อไม่ให้ตัวเลข
     * บอกจำนวนงานที่ผู้ดูไม่ได้เห็น ส่วน work_orders_count ใช้ตอบคำถามเดียวว่า
     * "โปรเจกต์นี้ยังไม่มีรายการงานเลยใช่ไหม"
     *
     * @param  Collection<int, WorkOrder>  $candidates
     * @param  Collection<int, WorkOrder>  $shown
     * @return Collection<int, WorkOrderList>
     */
    private function createdTodayProjects(User $member, ?User $viewer, Collection $candidates, Collection $shown, CarbonInterface $dayStart, CarbonInterface $dayEnd): Collection
    {
        $memberListIds = $candidates->pluck('work_order_list_id')->filter()->unique()->values();
        $shownByList = $shown->filter(fn (WorkOrder $task) => $task->work_order_list_id !== null)
            ->countBy(fn (WorkOrder $task) => (int) $task->work_order_list_id);

        return WorkOrderList::query()
            ->select(['id', 'name', 'user_id', 'priority', 'created_at'])
            ->with('user:id,department_id')
            ->withCount('workOrders')
            ->whereNull('archived_at')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->where(fn (Builder $query) => $query
                ->where('user_id', $member->id)
                ->orWhereIn('id', $memberListIds))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (WorkOrderList $list) => $shownByList->has((int) $list->id)
                || ($viewer !== null && $viewer->can('view', $list)))
            ->each(fn (WorkOrderList $list) => $list->setAttribute('shown_task_count', (int) $shownByList->get((int) $list->id, 0)))
            ->values();
    }

    /**
     * กลุ่มความเร่งด่วนของงานในรายการวันนี้ ใช้ทั้งการเรียงลำดับและป้ายกำกับใน Blade
     * จึงคำนวณที่เดียวแล้วติดไปกับตัวงาน
     */
    private function todayBucket(WorkOrder $task): string
    {
        if (WorkBoardDesign::statusKey($task) === 'late') {
            return 'late';
        }

        $today = TodayWorkspace::calendarDate(now());

        if (TodayWorkspace::calendarDate($task->job_due_at) === $today) {
            return 'due_today';
        }

        if (TodayWorkspace::calendarDate($task->job_start_at) === $today) {
            return 'starts_today';
        }

        return 'active';
    }

    private function applyTaskFilters($query, string $status, int $projectId, string $prefix = ''): void
    {
        if ($projectId > 0) {
            $query->where($prefix.'work_order_list_id', $projectId);
        }

        if ($status === '' || ! isset(WorkBoardDesign::STATUSES[$status])) {
            return;
        }

        $statusColumn = $prefix.'job_status';
        $dueColumn = $prefix.'job_due_at';
        $today = now()->startOfDay();

        match ($status) {
            'paused' => $query->where($statusColumn, 5),
            'late' => $query->where(function ($late) use ($statusColumn, $dueColumn, $today): void {
                $late->where($statusColumn, 6)
                    ->orWhere(function ($overdue) use ($statusColumn, $dueColumn, $today): void {
                        $overdue->whereNotIn($statusColumn, [4, 5])
                            ->whereNotNull($dueColumn)
                            ->where($dueColumn, '<', $today);
                    });
            }),
            'done' => $query->where($statusColumn, 4),
            'doing' => $this->applyActiveStatus($query, $statusColumn, $dueColumn, $today, 2),
            'review' => $this->applyActiveStatus($query, $statusColumn, $dueColumn, $today, 3),
        };
    }

    private function applyActiveStatus($query, string $statusColumn, string $dueColumn, Carbon $today, int $status): void
    {
        $query->where($statusColumn, $status)
            ->where(function ($notLate) use ($dueColumn, $today): void {
                $notLate->whereNull($dueColumn)->orWhere($dueColumn, '>=', $today);
            });
    }
}
