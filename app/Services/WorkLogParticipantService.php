<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\AuditTrail;
use App\Support\WorkLogWeekdays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ผู้ร่วมงานของบันทึกงานประจำวัน
 *
 * งานปฏิบัติการหลายอย่างทำกันหลายคน เช่น ตรวจสอบคอมพิวเตอร์ตอนเช้าที่ขึ้นไป
 * กันสองคน การให้ทั้งคู่พิมพ์บันทึกของตัวเองแยกกันทำให้เสียเวลาซ้ำและทำให้
 * รายงานนับงานชิ้นเดียวเป็นสองรายการ
 *
 * เงื่อนไขว่าใครถูกเพิ่มได้ ใช้กติกาเดียวกับผู้ร่วมงานของงานโครงการใน
 * CollaboratorInvitationService คือ บัญชีเปิดใช้งาน + role เป็น user +
 * อยู่แผนกเดียวกับงานนั้น
 *
 * ต่างกันที่ไม่มีขั้นตอนรออนุมัติ เพราะบันทึกงานประจำวันเป็นการบันทึกสิ่งที่
 * เกิดขึ้นไปแล้ว ไม่ใช่การมอบหมายงานให้ใครไปทำ การใส่ขั้นอนุมัติจะทำให้
 * เรื่องที่ควรใช้เวลาไม่กี่วินาทีกลายเป็นงานเอกสาร
 */
class WorkLogParticipantService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly WorkLogService $logs,
    ) {}

    /**
     * ตั้งรายชื่อผู้ร่วมงานของบันทึกหนึ่งให้ตรงกับที่ส่งมา
     *
     * id ที่ไม่ผ่านเงื่อนไขจะถูกตัดทิ้งเงียบ ๆ ไม่ใช่โยน error เพราะรายชื่อมาจาก
     * ตัวเลือกที่เซิร์ฟเวอร์เป็นคนสร้างอยู่แล้ว การส่งค่าที่ใช้ไม่ได้มาจึงเป็น
     * ความพยายามเลี่ยงกติกา ไม่ใช่ความผิดพลาดที่ผู้ใช้ต้องได้รับคำอธิบาย
     *
     * @param  array<int, mixed>  $userIds
     * @return int จำนวนผู้ร่วมงานหลังตั้งค่า
     */
    public function sync(WorkLog $log, User $actor, array $userIds): int
    {
        // สำเนาของผู้ร่วมงานเชิญคนเพิ่มเองไม่ได้ ไม่งั้นสำเนาจะแตกต่อเป็นสำเนาของสำเนา
        // รายชื่อคนที่ไปด้วยกันมาจากต้นฉบับเสมอ (ดู WorkLogPresenter::sharedFrom())
        if ($log->shared_from_work_log_id !== null) {
            return $log->participants()->count();
        }

        $eligible = $this->eligibleIds($log, $userIds);
        $before = $log->participants()->pluck('users.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        DB::transaction(function () use ($log, $actor, $eligible): void {
            $log->participants()->sync(
                collect($eligible)->mapWithKeys(fn (int $id): array => [$id => ['added_by' => $actor->id]])->all()
            );
        });

        $after = collect($eligible)->sort()->values()->all();

        if ($before !== $after) {
            AuditTrail::log(
                'work_log_participants_synced',
                $log,
                sprintf('ปรับผู้ร่วมงานของบันทึก "%s" เป็น %d คน', $log->title, count($after)),
                ['before' => $before, 'after' => $after]
            );
        }

        $this->syncSharedCopies($log, $actor, $after);

        // แจ้งเฉพาะคนที่เพิ่งถูกเพิ่มเข้ามา การแก้ไขบันทึกเดิมซ้ำ ๆ จึงไม่ยิง
        // แจ้งเตือนเดิมซ้ำให้คนที่อยู่ในรายชื่อมาตั้งแต่แรก
        $this->notifyAddedToLog($log, $actor, array_values(array_diff($after, $before)));

        return count($after);
    }

    /**
     * งานนอกสถานที่ที่ไปกันหลายคน — ผู้ร่วมงานแต่ละคนได้รายการของตัวเอง
     *
     * คนสร้างเพิ่มทุกคนที่จะไปได้ในครั้งเดียว แต่ละคนเห็นงานในบันทึกงานของตัวเอง
     * และบนปฏิทินแผนก แล้วปิดงานของตัวเองแยกกัน (เสร็จสิ้น / พบปัญหา)
     *
     * - คนที่ถูกเอาออก: สำเนาที่ยังไม่ปิดถูกลบ สำเนาที่ปิดแล้วเป็นประวัติจริงจึงเก็บไว้
     * - สำเนาที่ยังไม่ปิด: ชื่องาน สถานที่ วัน และช่วงเวลาตามต้นฉบับทุกครั้งที่ต้นฉบับถูกแก้
     * - งานประจำที่บันทึกเอง (kind = routine) ยังเป็นผู้ร่วมงานแบบรายชื่อตามเดิม
     *
     * @param  array<int, int>  $participantIds
     */
    private function syncSharedCopies(WorkLog $log, User $actor, array $participantIds): void
    {
        $targets = $log->kind === 'field' ? $participantIds : [];
        $copies = WorkLog::query()
            ->where('shared_from_work_log_id', $log->id)
            ->get()
            ->keyBy(fn (WorkLog $copy): int => (int) $copy->user_id);

        foreach ($copies as $userId => $copy) {
            $unfinished = in_array($copy->status, ['open', 'in_progress'], true);

            if (! in_array($userId, $targets, true)) {
                if ($unfinished) {
                    $this->logs->delete($copy, $actor);
                }

                continue;
            }

            if ($unfinished) {
                $copy->update($this->sharedAttributes($log));
            }
        }

        $missing = array_values(array_diff($targets, $copies->keys()->all()));

        if ($missing === []) {
            return;
        }

        User::query()->whereKey($missing)->get(['id', 'department_id'])
            ->each(function (User $person) use ($log, $actor): void {
                $copy = WorkLog::create([
                    'user_id' => $person->id,
                    'created_by' => $actor->id,
                    'department_id' => $person->department_id,
                    'shared_from_work_log_id' => $log->id,
                    'kind' => 'field',
                    // ผู้ร่วมงานต้องยืนยันเองว่าไปทำแล้ว จึงเริ่มที่ "รอเริ่ม" เสมอ
                    'status' => 'open',
                    'source' => 'manual',
                    ...$this->sharedAttributes($log),
                ]);

                AuditTrail::log(
                    'work_log_shared_copy_created',
                    $copy,
                    sprintf('เพิ่มงานนอกสถานที่ "%s" ลงบันทึกงานของผู้ร่วมงาน', $copy->title),
                    ['after' => ['shared_from_work_log_id' => $log->id, 'user_id' => $person->id]]
                );
            });
    }

    /**
     * ข้อมูลที่สำเนาได้จากต้นฉบับ — ไม่รวมโปรเจกต์/งาน เพราะเป็นของคนสร้างเท่านั้น
     *
     * ช่วงเวลาของต้นฉบับถูกเก็บเป็น "เวลาที่วางไว้" ของสำเนา ตอนผู้ร่วมงานกดเสร็จ
     * WorkLogService จึงเติมเวลาเดียวกันให้
     *
     * @return array<string, mixed>
     */
    private function sharedAttributes(WorkLog $log): array
    {
        return [
            'work_log_category_id' => $log->work_log_category_id,
            'title' => $log->title,
            'details' => $log->details,
            'location' => $log->location,
            'requester_name' => $log->requester_name,
            'work_date' => $log->work_date?->format('Y-m-d'),
            'planned_start_at' => $log->started_at,
            'planned_end_at' => $log->ended_at,
        ];
    }

    /**
     * ตั้งรายชื่อผู้ร่วมงานของแม่แบบงานประจำ
     *
     * คนที่ถูกเพิ่มจะได้รายการของงานประจำนี้ในไทม์ไลน์ของตัวเองทุกวันที่ถึงกำหนด
     * และต้องยืนยันว่าทำแล้วด้วยตัวเอง ต่างจากผู้ร่วมงานของบันทึกรายวันที่เป็น
     * เพียงการบอกว่างานชิ้นนั้นทำด้วยกันในรายการของเจ้าของคนเดียว
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, int> id ของคนที่เพิ่งถูกเพิ่มเข้ามาในรอบนี้
     */
    public function syncTemplate(WorkLogTemplate $template, User $actor, array $userIds): array
    {
        $eligible = $this->eligibleTemplateIds($template, $userIds);
        $before = $template->participants()->pluck('users.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        DB::transaction(function () use ($template, $actor, $eligible): void {
            $template->participants()->sync(
                collect($eligible)->mapWithKeys(fn (int $id): array => [$id => ['added_by' => $actor->id]])->all()
            );
        });

        $after = collect($eligible)->sort()->values()->all();

        if ($before !== $after) {
            AuditTrail::log(
                'work_log_template_participants_synced',
                $template,
                sprintf('ปรับผู้ร่วมงานของแม่แบบ "%s" เป็น %d คน', $template->title, count($after)),
                ['before' => $before, 'after' => $after]
            );
        }

        $added = array_values(array_diff($after, $before));

        $this->notifyAddedToTemplate($template, $actor, $added);

        return $added;
    }

    /**
     * กรอง id ที่ส่งมาให้เหลือเฉพาะคนที่เพิ่มเข้าแม่แบบได้จริง
     *
     * ใช้เงื่อนไขเดียวกับผู้ร่วมงานของบันทึกรายวัน (แผนกเดียวกับเจ้าของแม่แบบ
     * บัญชีเปิดใช้งาน role = user) เพื่อไม่ให้มีกติกาสองชุด
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, int>
     */
    public function eligibleTemplateIds(WorkLogTemplate $template, array $userIds): array
    {
        $owner = $template->user ?: User::find($template->user_id);

        if (! $owner instanceof User) {
            return [];
        }

        $allowed = $this->candidatesForOwner($owner)->pluck('id')->map(fn ($id): int => (int) $id);

        return collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $allowed->contains($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * แจ้งเตือนคนที่เพิ่งถูกเพิ่มเข้าแม่แบบงานประจำ
     *
     * ข้อความบอกช่วงเวลาที่ตั้งไว้ด้วย เพราะสิ่งที่ผู้รับต้องรู้จริง ๆ คือ
     * "ต้องเข้าไปทำตอนไหน" ไม่ใช่แค่ว่ามีชื่อตัวเองเพิ่มเข้ามา
     *
     * @param  array<int, int>  $userIds
     */
    private function notifyAddedToTemplate(WorkLogTemplate $template, User $actor, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $window = $template->plannedWindowLabel();

        $this->notifications->notifyDetached(
            $userIds,
            'work_log_routine_assigned',
            'มีงานประจำที่ต้องทำเพิ่ม',
            sprintf(
                '%s เพิ่มคุณเข้างาน "%s" (%s%s)',
                $actor->name,
                $template->title,
                WorkLogWeekdays::label((int) $template->weekday_mask),
                $window === null ? '' : ' เวลา '.$window
            ),
            $actor,
            [
                'work_log_template_id' => $template->id,
            ]
        );
    }

    /**
     * แจ้งเตือนคนที่เพิ่งถูกเพิ่มเป็นผู้ร่วมงานของบันทึกหนึ่ง
     *
     * เดิมการเพิ่มคนไม่ส่งอะไรเลย คนที่ถูกเลือกจึงไม่มีทางรู้ว่าชื่อตัวเองไปอยู่ใน
     * บันทึกของใคร นอกจากบังเอิญเปิดไปเจอ
     *
     * @param  array<int, int>  $userIds
     */
    private function notifyAddedToLog(WorkLog $log, User $actor, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->notifications->notifyDetached(
            $userIds,
            'work_log_participant_added',
            'ถูกเพิ่มเป็นผู้ร่วมงาน',
            $log->kind === 'field'
                ? sprintf('%s เพิ่มคุณเข้างานนอกสถานที่ "%s" — รายการอยู่ในบันทึกงานของคุณแล้ว', $actor->name, $log->title)
                : sprintf('%s บันทึกว่าคุณทำงาน "%s" ด้วยกัน', $actor->name, $log->title),
            $actor,
            [
                'work_log_id' => $log->id,
                'work_date' => $log->work_date?->format('Y-m-d'),
            ]
        );
    }

    /**
     * กรอง id ที่ส่งมาให้เหลือเฉพาะคนที่เพิ่มได้จริง
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, int>
     */
    public function eligibleIds(WorkLog $log, array $userIds): array
    {
        $ids = collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            // เจ้าของบันทึกไม่ใช่ "ผู้ร่วมงาน" ของตัวเอง
            ->reject(fn (int $id): bool => $id === (int) $log->user_id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->eligibleQuery($log)
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * ตัวเลือกผู้ร่วมงานของบันทึกหนึ่ง (เพื่อนร่วมแผนกที่ยังไม่ถูกเพิ่ม)
     *
     * @return Collection<int, User>
     */
    public function candidatesFor(WorkLog $log): Collection
    {
        return $this->eligibleQuery($log)
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);
    }

    /**
     * ตัวเลือกผู้ร่วมงานสำหรับบันทึกที่ยังไม่ถูกสร้าง
     *
     * ใช้ตอนเปิดหน้าเพื่อเตรียมรายชื่อไว้ในฟอร์ม โดยอิงแผนกของผู้บันทึกเอง
     * ซึ่งเป็นค่าเดียวกับที่ WorkLogService จะบันทึกเป็น department_id
     *
     * @return Collection<int, User>
     */
    public function candidatesForOwner(User $owner): Collection
    {
        if ($owner->department_id === null) {
            return collect();
        }

        return User::query()
            ->where('role', 'user')
            ->where('is_department_head', false)
            ->where('is_active', true)
            ->where('department_id', $owner->department_id)
            ->whereKeyNot($owner->id)
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);
    }

    /**
     * คนที่เพิ่มเป็นผู้ร่วมงานของบันทึกนี้ได้
     *
     * แผนกอ้างอิงคือแผนกที่บันทึกไว้ตอนสร้าง (snapshot) แล้ว fallback ไปที่แผนก
     * ปัจจุบันของเจ้าของ ให้ตรงกับ WorkLogPolicy::logDepartmentId() ที่ใช้ตัดสิน
     * ว่าหัวหน้าคนไหนเห็นบันทึกนี้ได้ ทั้งสองเรื่องจึงอิงแผนกเดียวกันเสมอ
     */
    private function eligibleQuery(WorkLog $log): Builder
    {
        $departmentId = $log->department_id ?: $log->user?->department_id;

        return User::query()
            ->where('role', 'user')
            ->where('is_department_head', false)
            ->where('is_active', true)
            ->when(
                $departmentId !== null,
                fn (Builder $query) => $query->where('department_id', $departmentId),
                // บันทึกที่ไม่มีแผนกเลยไม่ควรเพิ่มใครได้ เพราะไม่มีขอบเขตให้ตรวจ
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->whereKeyNot($log->user_id);
    }
}
