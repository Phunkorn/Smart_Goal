<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\DailyBriefAcknowledgement;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Support\AnnouncementDesign;
use App\Support\RoleLabel;
use App\Support\ThaiDate;
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
 *
 * การ์ดงานโปรเจกต์ตอบคำถามเดียวคือ "งานไหนล่าช้าที่สุด" ไม่ใช่ "วันนี้มีงานอะไร"
 * เดิมการ์ดกางงานของวันนี้ทั้งหมดจนผู้ใช้ต้องเลื่อนอ่านยาว สิ่งที่ต้องตัดสินใจก่อน
 * เริ่มวันจึงจมอยู่กลางรายการ การ์ดนี้แสดงเฉพาะงานที่เลยกำหนดส่งแล้วและยังไม่ปิด
 * เรียงจากล่าช้านานที่สุด แล้วตัดเหลือ OVERDUE_LIMIT รายการ ที่เหลือดูได้ในกล่อง "ดูทั้งหมด"
 */
class DailyBriefService
{
    /** จำนวนแถวงานประจำสูงสุดต่อการ์ด ที่เหลือบอกเป็นตัวเลขพร้อมลิงก์ไปดูทั้งหมด */
    public const TASK_LIMIT = 8;

    /** จำนวนอันดับงานโปรเจกต์ล่าช้าที่การ์ดสรุปแสดง — ที่เหลือดูได้ในกล่อง "ดูทั้งหมด" */
    public const OVERDUE_LIMIT = 5;

    /** สถานะ "เสร็จแล้ว/ปิดงาน" — งานที่ปิดแล้วไม่ใช่งานล่าช้าแม้กำหนดส่งจะผ่านไปแล้ว */
    private const DONE_STATUS = 4;

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

        $overdueTasks = $this->overdueProjectTasks($user);
        $routineTasks = $this->routineTasks($user, $today);
        $announcements = $this->announcements->activeFor($user, $today)
            ->map(fn (Announcement $announcement): array => $this->presentAnnouncement($announcement, $lastAcknowledgedAt));

        return [
            'date' => $today,
            'date_label' => AnnouncementDesign::longDate($businessDay),
            'department_name' => $user->department?->department_name,
            'overdue_tasks' => $overdueTasks->take(self::OVERDUE_LIMIT)->values()->all(),
            // รายการเต็มสำหรับกล่อง "ดูทั้งหมด" ที่ซ้อนบนสรุป — เปิดดูได้โดยไม่ต้องออกจากสรุป
            'overdue_all_tasks' => $overdueTasks->values()->all(),
            'overdue_total' => $overdueTasks->count(),
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
     * งานโปรเจกต์ที่ล่าช้าที่สุดของผู้ใช้คนนี้ เรียงจากล่าช้านานที่สุด
     *
     * ขอบเขตการมองเห็นเป็นชุดเดียวกับการ์ดเดิม (MemberWorkloadQuery::forMember บวก
     * งานที่อนุมัติแล้วและโปรเจกต์ที่ยังไม่จัดเก็บ) เปลี่ยนแค่เงื่อนไขการคัดเลือก
     *
     * การจัดอันดับเกิดในฐานข้อมูลบนชุดงานที่ผู้ใช้เห็นได้ทั้งหมด ไม่ใช่บนชุดของวันนี้
     * ที่ถูกตัดมาก่อน เพราะงาน "รอตรวจ" ที่เลยกำหนดไม่อยู่ในนิยามของวันนี้ แต่ยังล่าช้า
     * และต้องถูกนับในอันดับ
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function overdueProjectTasks(User $user): Collection
    {
        $query = $this->workload->forMember($user)
            // ผู้รับผิดชอบยังไม่เห็นงานที่รออนุมัติในหน้างานของฉัน (WorkOrder::scopeInvolving)
            // สรุปต้องไม่เปิดเผยงานเหล่านั้นก่อน
            ->where('approval_status', 'approved')
            // โปรเจกต์ที่จัดเก็บแล้วไม่อยู่ในพื้นที่ทำงานปัจจุบัน
            ->where(fn (Builder $list) => $list
                ->whereNull('work_order_list_id')
                ->orWhereHas('taskList', fn (Builder $project) => $project->whereNull('archived_at')));

        // งานที่เลยกำหนดแต่ยังไม่ถูกดันเป็น "ล่าช้า" ต้องถูกซิงก์ก่อน ป้ายสถานะในสรุป
        // จึงตรงกับสถานะที่เก็บไว้จริง เหมือนที่หน้า "งานของฉัน" ทำ
        TodayWorkspace::synchronizeLate($query);

        return (clone $query)->with('taskList')
            // "ล่าช้าจริง" = เลยขณะเวลาที่ครบกำหนดแล้วและยังไม่ปิดงาน (TodayWorkspace::isOverdue)
            ->whereNotNull('work_orders.job_due_at')
            ->where('work_orders.job_due_at', '<', now())
            ->where('work_orders.job_status', '!=', self::DONE_STATUS)
            // กำหนดส่งเก่าสุด = ล่าช้านานสุด จัดอันดับในฐานข้อมูลโดยไม่ดึงงานที่ไม่ล่าช้ามาคัดใน PHP
            ->orderBy('work_orders.job_due_at')
            // ลำดับรองที่แน่นอนเมื่อล่าช้าเท่ากัน: งานด่วนก่อน แล้วตัดสินด้วยคีย์
            ->orderByDesc('work_orders.job_priority')
            ->orderBy('work_orders.job_id')
            ->get()
            ->map(fn (WorkOrder $task): array => $this->presentOverdueTask($task));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOverdueTask(WorkOrder $task): array
    {
        $overdueDays = TodayWorkspace::overdueDays($task);

        return [
            'id' => $task->job_id,
            'title' => $task->job_topic,
            'project' => $task->taskList?->name ?? 'งานทั่วไป',
            'due_label' => ThaiDate::day(TodayWorkspace::businessNow($task->job_due_at)),
            'overdue_days' => $overdueDays,
            // งานที่เพิ่งเลยกำหนดวันนี้ยังนับเป็นศูนย์วัน ป้ายจึงต้องบอกเวลาที่เกิดขึ้นจริง
            'overdue_label' => $overdueDays > 0 ? 'ล่าช้า '.$overdueDays.' วัน' : 'เลยกำหนดวันนี้',
            'priority' => (int) $task->job_priority === 3 ? WorkBoardDesign::taskPriority(3) : null,
            'url' => route('mytasks.index', ['open_task' => $task->job_id]),
        ];
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
            'author' => $author,
            'author_role' => $author ? RoleLabel::withDepartment($author) : '',
            'posted_at' => AnnouncementDesign::postedAt($announcement->created_at),
            // ใหม่ = สร้างหรือแก้ไขหลังการรับทราบครั้งล่าสุด ผู้ที่ไม่เคยรับทราบเลยเห็นทุกใบเป็นของใหม่
            'is_new' => $lastAcknowledgedAt === null
                || ($changedAt !== null && $changedAt->greaterThan($lastAcknowledgedAt)),
        ];
    }
}
