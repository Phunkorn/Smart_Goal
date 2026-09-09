<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * อายุการเก็บบันทึกกิจกรรม
 *
 * ถังขยะมีกำหนด 30 วันมาตั้งแต่ต้น (ดู TrashRetention) แต่ activity_logs ไม่เคยมี
 * การล้างเลยแม้แต่ทางเดียว ทุกครั้งที่มีคนเข้าสู่ระบบหรือแก้ฟิลด์ใดก็ตาม จะมีแถวใหม่
 * พร้อมสำเนา before/after เก็บไว้ถาวร ตารางนี้จึงเป็นสิ่งที่โตเร็วที่สุดในระบบ
 * และบนเครื่องคลาวด์ที่พื้นที่ฐานข้อมูลมีจำกัด การไม่มีวันหมดอายุคือปัญหา
 *
 * นโยบายแบ่งตามคุณค่าของหลักฐาน ไม่ใช่ตัดอายุเดียวทั้งตาราง
 *
 *  - เหตุการณ์สำคัญ (เข้าออกระบบ รหัสผ่าน การลบ การกู้คืน การย้อนค่า) เก็บ 365 วัน
 *  - บันทึกการแก้ไขทั่วไป เก็บ 90 วัน
 *
 * หลักการที่ต้องรักษาไว้เสมอ: บันทึกว่า "ใครลบอะไร" ต้องอยู่นานกว่าตัวข้อมูลที่ถูกลบ
 * ด้วยเหตุนี้ activity_pruned จึงถูกจัดเป็นเหตุการณ์สำคัญเช่นกัน การล้างบันทึก
 * จะได้ไม่ลบร่องรอยของตัวมันเอง
 */
class LogRetention
{
    public const CRITICAL_DAYS = 365;

    public const ROUTINE_DAYS = 90;

    /**
     * เหตุการณ์ที่ต้องตรวจสอบย้อนหลังได้นาน
     *
     * ต่อยอดจาก AuditSnapshot::AUTH_ACTIONS แล้วเติมการกระทำที่ทำลายหรือเขียนทับข้อมูล
     * ทุกค่าต้องเป็นตัวพิมพ์เล็ก เพราะเทียบกับ action ที่ normalize แล้ว
     *
     * @var list<string>
     */
    public const CRITICAL_ACTIONS = [
        ...AuditSnapshot::AUTH_ACTIONS,
        'password_changed',
        'password_reset',
        'deleted',
        'delete',
        'purged',
        'bulk_purged',
        'restored',
        'restore',
        'reverted',
        'activity_pruned',
    ];

    /**
     * แถวที่พ้นอายุแล้วตามนโยบาย
     *
     * เขียนเป็นคิวรีเดียวสองเงื่อนไข ไม่ใช่สองคิวรีต่อกัน เพื่อให้ทั้งการนับจำนวนบนปุ่ม
     * และการลบจริงอ่านจากนิยามเดียวกันเสมอ ตัวเลขบนปุ่มจึงตรงกับสิ่งที่ปุ่มจะทำ
     *
     * @return Builder<ActivityLog>
     */
    public static function prunableActivity(?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        $criticalCutoff = $now->subDays(self::CRITICAL_DAYS);
        $routineCutoff = $now->subDays(self::ROUTINE_DAYS);

        return ActivityLog::query()->where(function (Builder $query) use ($criticalCutoff, $routineCutoff) {
            $query->where(fn (Builder $critical) => $critical
                ->whereIn(DB::raw('LOWER(action)'), self::CRITICAL_ACTIONS)
                ->where('created_at', '<', $criticalCutoff))
                ->orWhere(fn (Builder $routine) => $routine
                    ->whereNotIn(DB::raw('LOWER(action)'), self::CRITICAL_ACTIONS)
                    ->where('created_at', '<', $routineCutoff));
        });
    }

    public static function prunableCount(): int
    {
        return self::prunableActivity()->count();
    }

    /**
     * ลบบันทึกที่พ้นอายุ แล้วบันทึกไว้ว่าใครสั่งลบ
     *
     * ลบทีละก้อนแบบ chunkById เหมือน TrashRetention::purgeExpired() เพราะตารางนี้
     * อาจมีหลักแสนแถวบนเครื่องที่ใช้งานจริง การสั่ง delete รวดเดียวจะล็อกตารางนาน
     *
     * เขียนบันทึกสรุปเพียงแถวเดียว ไม่ใช่แถวต่อรายการที่ลบ มิฉะนั้นการล้างขยะ
     * จะสร้างขยะก้อนใหม่ที่ใหญ่กว่าเดิม
     */
    public static function pruneActivity(?User $actor = null): int
    {
        $now = CarbonImmutable::now();
        $deleted = 0;

        self::prunableActivity($now)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted) {
                DB::transaction(function () use ($rows, &$deleted) {
                    $deleted += ActivityLog::whereIn('id', $rows->pluck('id'))->delete();
                });
            });

        if ($deleted > 0) {
            AuditTrail::log('activity_pruned', null, 'ล้างบันทึกกิจกรรมที่พ้นอายุ '.$deleted.' รายการ', [
                'deleted' => $deleted,
                'critical_days' => self::CRITICAL_DAYS,
                'routine_days' => self::ROUTINE_DAYS,
                'pruned_by' => $actor?->id,
            ]);
        }

        return $deleted;
    }
}
