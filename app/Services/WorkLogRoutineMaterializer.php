<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * สร้างรายการงานประจำของแต่ละวันจากแม่แบบ
 *
 * เรียกจากสองทาง: ตอนเปิดหน้าบันทึกงาน และจาก artisan command ที่ตั้งเวลาไว้
 * โดยตั้งใจให้ "ตอนเปิดหน้า" เป็นแหล่งความจริง ส่วนคำสั่งตามเวลาเป็นเพียงตัวช่วย
 *
 * เหตุผล: deploy/README.md ของระบบนี้อธิบายการติดตั้งแบบอัป ZIP บน DirectAdmin
 * และไม่มีขั้นตอนตั้ง cron ให้ schedule:run เลย ถ้าให้งานประจำเกิดจาก cron
 * อย่างเดียวแล้ว cron ไม่ถูกตั้ง ฟีเจอร์นี้จะเงียบสนิทบนเครื่องจริงโดยไม่มี error
 * ให้ใครเห็น (โค้ดเบสนี้มี precedent อยู่แล้วที่ AuditController::index() เรียก
 * TrashRetention::purgeExpired() ตอนเปิดหน้า)
 *
 * ความถูกต้องไม่ขึ้นกับว่าเส้นทางไหนทำงาน เพราะ unique index
 * work_logs_template_day_unique เป็นผู้ตัดสินสุดท้ายว่าห้ามซ้ำ
 */
class WorkLogRoutineMaterializer
{
    public function materializeToday(User $owner): int
    {
        return $this->materializeDay($owner, TodayWorkspace::businessNow()->startOfDay());
    }

    /**
     * สร้างรายการงานประจำของวันที่กำหนด
     *
     * @return int จำนวนรายการที่สร้างใหม่ (0 หมายถึงสร้างครบไปแล้ว ไม่ใช่ข้อผิดพลาด)
     */
    public function materializeDay(User $owner, CarbonInterface $businessDay): int
    {
        if (! $this->isMaterializable($businessDay)) {
            return 0;
        }

        $due = $this->dueTemplates($owner, $businessDay);

        if ($due->isEmpty()) {
            return 0;
        }

        $missing = $due->reject(
            fn (WorkLogTemplate $template): bool => in_array(
                $template->id,
                $this->existingTemplateIds($owner, $businessDay, $due),
                true
            )
        );

        $created = 0;

        foreach ($missing as $template) {
            $created += $this->createFor($owner, $template, $businessDay);
        }

        $this->advanceCursor($due, $businessDay);

        return $created;
    }

    /**
     * งานประจำของวันนั้นที่ยังไม่มีรายการอยู่จริง
     *
     * ใช้แสดง "รายการที่ยังไม่ได้บันทึก" ของวันย้อนหลัง โดยไม่สร้างข้อมูลให้เอง
     * เพราะการสร้างรายการค้างย้อนหลังให้คนที่เพิ่งกลับจากลา จะกลายเป็นสัญญาณ
     * "ไม่ได้ทำงาน" ปลอม ๆ ที่ไปเพี้ยนในรายงานภาระงาน
     *
     * @return Collection<int, WorkLogTemplate>
     */
    public function pendingRoutinesFor(User $owner, CarbonInterface $businessDay): Collection
    {
        if (! $this->isMaterializable($businessDay)) {
            return collect();
        }

        $due = $this->dueTemplates($owner, $businessDay);

        if ($due->isEmpty()) {
            return $due;
        }

        $existing = $this->existingTemplateIds($owner, $businessDay, $due);

        return $due->reject(fn (WorkLogTemplate $template): bool => in_array($template->id, $existing, true))
            ->values();
    }

    /**
     * แม่แบบที่ถึงกำหนดในวันนั้น
     *
     * การเทียบวันในสัปดาห์ต้องใช้วันตามเวลากรุงเทพเสมอ เพราะเช้าวันจันทร์ 06:00
     * ที่กรุงเทพยังเป็นวันอาทิตย์ 23:00 ตามเวลา UTC ถ้าเทียบด้วย UTC ระบบจะสร้าง
     * งานประจำของวันอาทิตย์ให้ทุกเช้า และไม่สร้างของวันจันทร์เลย
     *
     * @return Collection<int, WorkLogTemplate>
     */
    private function dueTemplates(User $owner, CarbonInterface $businessDay): Collection
    {
        $day = $businessDay->format('Y-m-d');

        return WorkLogTemplate::query()
            ->where('user_id', $owner->id)
            ->active()
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $day))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (WorkLogTemplate $template): bool => WorkLogWeekdays::matches(
                (int) $template->weekday_mask,
                $businessDay
            ))
            ->values();
    }

    /**
     * แม่แบบที่มีรายการของวันนั้นอยู่แล้ว
     *
     * นับรายการที่ถูกลบไปแล้วด้วย (withTrashed) ด้วยเหตุผลสองข้อ:
     * 1. soft delete ไม่ได้เอาแถวออกจากตาราง unique index จึงยังกันการ insert ซ้ำอยู่
     * 2. การที่เจ้าของลบรายการงานประจำของวันนี้ทิ้ง เป็นการบอกว่าวันนี้ไม่ได้ทำ
     *    ระบบจึงต้องไม่สร้างกลับมาให้ใหม่ทุกครั้งที่เปิดหน้า
     *
     * @param  Collection<int, WorkLogTemplate>  $templates
     * @return array<int, int>
     */
    private function existingTemplateIds(User $owner, CarbonInterface $businessDay, Collection $templates): array
    {
        return WorkLog::withTrashed()
            ->where('user_id', $owner->id)
            ->whereDate('work_date', $businessDay->format('Y-m-d'))
            ->whereIn('work_log_template_id', $templates->pluck('id'))
            ->pluck('work_log_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * สร้างรายการหนึ่งรายการ
     *
     * รายการที่สร้างให้เป็นเพียง "สิ่งที่ต้องทำวันนี้" จึงมีสถานะ open และไม่มี
     * จำนวนนาที ระบบไม่เดาแทนว่าทำไปแล้วกี่นาที ผู้ใช้เป็นคนกดเริ่ม/จบเอง
     *
     * @return int 1 เมื่อสร้างสำเร็จ, 0 เมื่อมีอยู่แล้ว (แข่งกันจากอีกแท็บหรือ cron)
     */
    private function createFor(User $owner, WorkLogTemplate $template, CarbonInterface $businessDay): int
    {
        // เวลาเริ่มที่ตั้งไว้ในแม่แบบมีไว้ให้ไทม์ไลน์เรียงลำดับได้ถูกตั้งแต่แรก
        // ไม่ได้แปลว่างานเริ่มไปแล้ว (duration ยังเป็น null และสถานะยังเป็น open)
        $startedAt = $template->default_start_time === null
            ? null
            : $businessDay->copy()
                ->setTimeFromTimeString((string) $template->default_start_time)
                ->utc();

        try {
            DB::transaction(function () use ($owner, $template, $businessDay, $startedAt): void {
                WorkLog::create([
                    'user_id' => $owner->id,
                    'created_by' => $owner->id,
                    'department_id' => $owner->department_id,
                    'work_log_category_id' => $template->work_log_category_id,
                    'work_log_template_id' => $template->id,
                    'kind' => $template->kind ?: WorkLogDesign::DEFAULT_KIND,
                    'status' => 'open',
                    'source' => 'template',
                    'title' => $template->title,
                    'details' => $template->details,
                    'work_date' => $businessDay->format('Y-m-d'),
                    'started_at' => $startedAt,
                ]);
            });

            return 1;
        } catch (QueryException $exception) {
            // อีกแท็บหรือ cron สร้างไปก่อนแล้วในเสี้ยววินาทีเดียวกัน
            // ฐานข้อมูลเป็นผู้ตัดสิน ผลลัพธ์สุดท้ายจึงยังเหลือแถวเดียวเสมอ
            if ($this->isDuplicateKey($exception)) {
                return 0;
            }

            throw $exception;
        }
    }

    /**
     * เลื่อน cursor เพื่อให้การเปิดหน้าครั้งถัดไปไม่ต้องทำงานซ้ำ
     *
     * @param  Collection<int, WorkLogTemplate>  $templates
     */
    private function advanceCursor(Collection $templates, CarbonInterface $businessDay): void
    {
        $stale = $templates->filter(
            fn (WorkLogTemplate $template): bool => $template->last_materialized_on === null
                || $template->last_materialized_on->lessThan($businessDay)
        );

        if ($stale->isEmpty()) {
            return;
        }

        WorkLogTemplate::query()
            ->whereIn('id', $stale->pluck('id'))
            ->update(['last_materialized_on' => $businessDay->format('Y-m-d')]);
    }

    private function isMaterializable(CarbonInterface $businessDay): bool
    {
        $today = TodayWorkspace::businessNow()->startOfDay();

        return ! $businessDay->greaterThan($today)
            && ! $businessDay->lessThan($today->copy()->subDays(WorkLogDesign::MAX_BACKFILL_DAYS));
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(mb_strtolower($exception->getMessage()), 'unique');
    }
}
