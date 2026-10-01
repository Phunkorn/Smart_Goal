<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\DailyBriefAcknowledgement;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Support\AnnouncementDesign;
use App\Support\RoleLabel;
use App\Support\TodayWorkspace;
use App\Support\WorkBoardDesign;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * สรุปประจำวัน (Daily Brief) ที่แสดงครั้งแรกของวันให้พนักงานและหัวหน้าแผนก
 *
 * "ครั้งแรกของวัน" คือ "หน้าแรกที่เปิดในวันทำการไทยนั้นโดยที่ยังไม่ได้กดรับทราบ"
 * ไม่ได้ผูกกับ POST /login เพราะผู้ใช้ที่ติ๊ก "จดจำฉัน" ไม่ผ่าน AuthController::login()
 * เลย และ session ที่เปิดค้างข้ามเที่ยงคืนก็ควรเห็นสรุปของวันใหม่
 *
 * การรับทราบเก็บในตาราง daily_brief_acknowledgements ไม่ใช่ session เพราะ logout
 * ล้าง session ทิ้ง แต่กติกาคือวันนั้นต้องไม่แสดงซ้ำแม้ login ใหม่
 *
 * งานที่แสดงคือ "งานที่ฉันรับผิดชอบหรือร่วมงานโดยตรง" (MemberWorkloadQuery) ที่อนุมัติแล้ว
 * แม้เป็นหัวหน้าก็ไม่รวมงานทั้งแผนก สรุปนี้จึงสั้นกว่าหน้า "งานของฉัน > วันนี้" โดยตั้งใจ
 */
class DailyBriefService
{
    /** จำนวนแถวงานสูงสุดต่อการ์ด ที่เหลือบอกเป็นตัวเลขพร้อมลิงก์ไปดูทั้งหมด */
    public const TASK_LIMIT = 8;

    public function __construct(
        private readonly MemberWorkloadQuery $workload,
        private readonly WorkLogRoutineMaterializer $materializer,
        private readonly RoutineAccountabilityService $accountability,
        private readonly AnnouncementQueryService $announcements,
    ) {}

    /**
     * ผู้ใช้คนนี้เห็นสรุปประจำวันได้หรือไม่ — admin และ viewer ไม่เห็นเลย
     */
    public function isEligible(?User $user): bool
    {
        return $user !== null
            && $user->role === 'user'
            && $user->is_active
            && ! $user->must_change_password;
    }

    /**
     * ต้องแสดงสรุปของวันนี้หรือไม่ — ช่วงที่รับทราบแล้วเป็น query เดียวบน unique index
     */
    public function isPending(?User $user): bool
    {
        return $this->isEligible($user)
            && ! DailyBriefAcknowledgement::query()
                ->where('user_id', $user->id)
                ->whereDate('brief_date', AnnouncementDesign::today())
                ->exists();
    }

    /**
     * เนื้อหาของ modal — เรียกเฉพาะตอน isPending() เป็นจริง
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $businessDay = TodayWorkspace::businessNow()->startOfDay();
        $today = $businessDay->format('Y-m-d');
        $lastAcknowledgedAt = DailyBriefAcknowledgement::query()
            ->where('user_id', $user->id)
            ->whereDate('brief_date', '<', $today)
            ->latest('brief_date')
            ->value('acknowledged_at');

        $projectTasks = $this->projectTasks($user);
        $routineTasks = $this->routineTasks($user, $today);
        $announcements = $this->announcements->activeFor($user, $today)
            ->map(fn (Announcement $announcement): array => $this->presentAnnouncement($announcement, $lastAcknowledgedAt));

        return [
            'date' => $today,
            'date_label' => AnnouncementDesign::longDate($businessDay),
            'department_name' => $user->department?->department_name,
            'project_tasks' => $projectTasks->take(self::TASK_LIMIT)->values()->all(),
            // รายการเต็มสำหรับกล่อง "ดูทั้งหมด" ที่ซ้อนบนสรุป — เปิดดูได้โดยไม่ต้องออกจากสรุป
            'project_all_tasks' => $projectTasks->values()->all(),
            'project_total' => $projectTasks->count(),
            'routine_tasks' => $routineTasks->take(self::TASK_LIMIT)->values()->all(),
            'routine_all_tasks' => $routineTasks->values()->all(),
            'routine_total' => $routineTasks->count(),
            'department_announcements' => $announcements->where('audience', Announcement::AUDIENCE_DEPARTMENT)->values()->all(),
            'all_announcements' => $announcements->where('audience', Announcement::AUDIENCE_ALL)->values()->all(),
        ];
    }

    /**
     * บันทึกการรับทราบ — รับเฉพาะวันที่ตรงกับวันนี้ตามเวลาไทย
     *
     * modal ที่เปิดค้างตั้งแต่ก่อนเที่ยงคืนจะส่งวันของเมื่อวานมา ถ้ายอมบันทึกเป็นวันนี้
     * ผู้ใช้จะ "รับทราบ" สรุปของวันใหม่ทั้งที่ยังไม่เคยเห็นเนื้อหาของวันนั้น
     */
    public function acknowledge(User $user, string $briefDate): bool
    {
        if (! $this->isEligible($user) || $briefDate !== AnnouncementDesign::today()) {
            return false;
        }

        // insertOrIgnore บน unique (user_id, brief_date) ทำให้กดซ้ำหรือสองแท็บยิงพร้อมกันได้แถวเดียว
        DailyBriefAcknowledgement::query()->insertOrIgnore([
            'user_id' => $user->id,
            'brief_date' => $briefDate,
            'acknowledged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * งานโปรเจกต์/งานมอบหมายของวันนี้ — นิยาม "วันนี้" จาก TodayWorkspace::tasks() ชุดเดียวกับหน้างานของฉัน
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function projectTasks(User $user): Collection
    {
        $query = $this->workload->forMember($user)
            // ผู้รับผิดชอบยังไม่เห็นงานที่รออนุมัติในหน้างานของฉัน (WorkOrder::scopeInvolving)
            // สรุปต้องไม่เปิดเผยงานเหล่านั้นก่อน
            ->where('approval_status', 'approved')
            // โปรเจกต์ที่จัดเก็บแล้วไม่อยู่ในพื้นที่ทำงานปัจจุบัน
            ->where(fn (Builder $list) => $list
                ->whereNull('work_order_list_id')
                ->orWhereHas('taskList', fn (Builder $project) => $project->whereNull('archived_at')));

        // งานที่เลยกำหนดแต่ยังไม่ถูกดันเป็น "ล่าช้า" จะหลุดจากนิยามของวันนี้ จึงต้องซิงก์ก่อนอ่าน
        TodayWorkspace::synchronizeLate($query);

        $tasks = (clone $query)->with('taskList')
            ->orderBy('job_due_at')
            ->get();

        return TodayWorkspace::tasks($tasks)
            ->reject(fn (WorkOrder $task): bool => (int) $task->job_status === 4)
            // งานล่าช้าขึ้นก่อน เพราะเป็นสิ่งแรกที่ต้องจัดการของวัน — sortBy คงลำดับกำหนดส่งเดิมไว้ในกลุ่มเดียวกัน
            ->sortBy(fn (WorkOrder $task): int => WorkBoardDesign::statusKey($task) === 'late' ? 0 : 1)
            ->values()
            ->map(fn (WorkOrder $task): array => $this->presentTask($task));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(WorkOrder $task): array
    {
        $status = WorkBoardDesign::status($task);
        $priority = (int) $task->job_priority === 3 ? WorkBoardDesign::taskPriority(3) : null;

        return [
            'id' => $task->job_id,
            'title' => $task->job_topic,
            'project' => $task->taskList?->name ?? 'งานทั่วไป',
            'time' => $this->taskTimeLabel($task),
            'status_label' => $status['label'],
            'is_late' => WorkBoardDesign::statusKey($task) === 'late',
            'priority' => $priority,
            'url' => route('mytasks.index', ['open_task' => $task->job_id]),
        ];
    }

    private function taskTimeLabel(WorkOrder $task): string
    {
        $overdueDays = TodayWorkspace::overdueDays($task);

        if ($overdueDays > 0) {
            return 'เลยกำหนด '.$overdueDays.' วัน';
        }

        $progress = TodayWorkspace::timeProgress($task);

        if (! $progress) {
            return '';
        }

        if (! $progress['is_single_day']) {
            return $progress['progress_label'];
        }

        $start = TodayWorkspace::clockTime($task->job_start_at);
        $due = TodayWorkspace::clockTime($task->job_due_at);

        // เวลาเริ่ม 00:00 คือค่าตั้งต้นตอนผู้ใช้เลือกแค่วันที่ ไม่ใช่เวลาที่นัดไว้จริง
        return $start === TodayWorkspace::DEFAULT_START_TIME
            ? 'ภายใน '.$due.' น.'
            : $start.' - '.$due;
    }

    /**
     * งานประจำของวันนี้ — ลำดับ closeFor() แล้ว materializeToday() เดียวกับไอคอนงานประจำบน Topbar
     * (RoutineAttentionService::summary) ทั้งสองเมธอด idempotent เรียกซ้ำได้
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function routineTasks(User $user, string $today): Collection
    {
        $this->accountability->closeFor($user);
        $this->materializer->materializeToday($user);

        return WorkLog::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->whereNotNull('work_log_template_id')
            ->where('status', '!=', 'cancelled')
            ->orderByRaw('planned_start_at IS NULL')
            ->orderBy('planned_start_at')
            ->get()
            ->map(function (WorkLog $log) use ($today): array {
                $statusKey = WorkLogPresenter::displayStatus($log);
                $status = WorkLogDesign::status($statusKey);

                return [
                    'id' => $log->id,
                    'title' => $log->title,
                    'time' => $log->planned_start_at
                        ? TodayWorkspace::businessNow($log->planned_start_at)->format('H:i')
                        : '',
                    'status_label' => $status['label'],
                    'url' => route('daily-logs.index', ['date' => $today]).'#work-log-'.$log->id,
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAnnouncement(Announcement $announcement, mixed $lastAcknowledgedAt): array
    {
        $author = $announcement->author;
        $changedAt = $announcement->updated_at ?? $announcement->created_at;

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'audience' => $announcement->audience,
            'audience_meta' => AnnouncementDesign::audience($announcement->audience),
            'author' => $author,
            'author_role' => $author ? RoleLabel::withDepartment($author) : '',
            'posted_at' => AnnouncementDesign::postedAt($announcement->created_at),
            // ใหม่ = สร้างหรือแก้ไขหลังการรับทราบครั้งล่าสุด ผู้ที่ไม่เคยรับทราบเลยเห็นทุกใบเป็นของใหม่
            'is_new' => $lastAcknowledgedAt === null
                || ($changedAt !== null && $changedAt->greaterThan($lastAcknowledgedAt)),
        ];
    }
}
