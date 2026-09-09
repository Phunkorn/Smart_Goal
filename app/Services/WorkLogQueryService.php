<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Support\TodayWorkspace;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * การอ่านข้อมูลบันทึกงานประจำวัน พร้อมกติกาว่าใครเห็นอะไรในระดับ SQL
 *
 * แยกจาก WorkLogPolicy โดยตั้งใจ: policy ตัดสิน "รายการนี้คนนี้เปิดได้ไหม"
 * ส่วนที่นี่ตัดสิน "query ต้องกรองอะไรออกก่อนถึงมือผู้ใช้" ทั้งสองอิงกติกาเดียวกัน
 * (เจ้าของ / หัวหน้าแผนกของเจ้าของ / admin) แต่ใช้คนละจุดของ request
 */
class WorkLogQueryService
{
    /**
     * ขอบเขตข้อมูลที่ผู้ใช้คนหนึ่งมองเห็นได้
     *
     * viewer ไม่มีสิทธิ์เห็นบันทึกงานประจำวันของใครเลย จึงคืน query ที่ไม่มีผลลัพธ์
     * แทนการโยน exception เพราะจุดเรียกใช้บางแห่งเป็นการนับยอดประกอบหน้าอื่น
     * ส่วนการปิดกั้นการเข้าหน้าเป็นหน้าที่ของ route middleware และ policy
     */
    public function visibleQuery(User $viewer): Builder
    {
        $query = WorkLog::query();

        if ($viewer->role === 'viewer') {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->role === 'admin') {
            return $query;
        }

        if ($viewer->isDepartmentHead()) {
            // หัวหน้าเห็นทั้งของตัวเองและของลูกทีม โดยอิง department_id ที่บันทึกไว้
            // ตอนสร้าง (snapshot) และ fallback ไปที่แผนกปัจจุบันของเจ้าของเมื่อไม่มีค่า
            // เพื่อให้ตรงกับ WorkLogPolicy::logDepartmentId()
            return $query->where(function (Builder $scoped) use ($viewer): void {
                $scoped->where('user_id', $viewer->id)
                    ->orWhere('department_id', $viewer->department_id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner
                            ->where('department_id', $viewer->department_id)));
            });
        }

        return $query->where('user_id', $viewer->id);
    }

    /**
     * บันทึกงานของคนหนึ่งในหนึ่งวันทำการ เรียงตามเวลาเริ่มจริงหรือเวลาที่วางแผนไว้
     *
     * รายการที่ไม่ระบุเวลาไปอยู่ท้ายสุด เพราะไทม์ไลน์อ่านจากบนลงล่างตามเวลาจริง
     * การจัดเรียงทำในหน่วยความจำหลัง query เพื่อเลี่ยงไวยากรณ์ NULLS LAST
     * ที่ SQLite (ทดสอบ) กับ MySQL (production) เขียนไม่เหมือนกัน
     *
     * @return Collection<int, WorkLog>
     */
    public function dayFor(User $owner, CarbonInterface $businessDay): Collection
    {
        return WorkLog::query()
            // template.user ใช้บอกว่ารายการงานประจำนี้เป็นของแม่แบบที่คนอื่นตั้งไว้
            ->with(['category', 'project', 'task', 'attachments', 'user', 'participants', 'template.user'])
            ->where('user_id', $owner->id)
            ->whereDate('work_date', $businessDay->format('Y-m-d'))
            ->get()
            ->sortBy([
                fn (WorkLog $log): int => ($log->started_at ?? $log->planned_start_at) === null ? 1 : 0,
                fn (WorkLog $log): int => ($log->started_at ?? $log->planned_start_at)?->getTimestamp() ?? 0,
                fn (WorkLog $log): int => $log->id,
            ])
            ->values();
    }

    /**
     * รายชื่อคนที่ผู้ใช้เปิดดูไทม์ไลน์แทนได้ (ตัวเลือกในหน้าบันทึกงาน)
     *
     * คืน collection ว่างสำหรับพนักงานทั่วไป เพราะเห็นได้เฉพาะของตัวเอง
     * viewer ไม่เข้าเงื่อนไขใดเลยจึงได้ว่างเช่นกัน
     *
     * @return Collection<int, User>
     */
    public function visibleMembersFor(User $viewer): Collection
    {
        if ($viewer->role !== 'admin' && ! $viewer->isDepartmentHead()) {
            return collect();
        }

        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($viewer->isDepartmentHead(), fn (Builder $query) => $query
                ->where('department_id', $viewer->department_id))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);
    }

    /**
     * วันทำการที่หน้าจอกำลังแสดง จากพารามิเตอร์ ?date= ที่ผู้ใช้ส่งมา
     *
     * ค่าที่ผิดรูปแบบหรืออยู่นอกช่วงที่ยอมรับได้จะถูกปัดกลับเป็น "วันนี้" เงียบ ๆ
     * แทนการโยน error เพราะเป็นพารามิเตอร์ของการนำทาง ไม่ใช่ข้อมูลที่ผู้ใช้กรอก
     */
    public function resolveBusinessDay(?string $requested): CarbonInterface
    {
        $today = TodayWorkspace::businessNow()->startOfDay();

        if ($requested === null || $requested === '') {
            return $today;
        }

        try {
            $candidate = TodayWorkspace::businessNow(
                Carbon::createFromFormat('Y-m-d', $requested, TodayWorkspace::BUSINESS_TIMEZONE)
            )->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        if ($candidate->greaterThan($today)) {
            return $today;
        }

        return $candidate;
    }
}
