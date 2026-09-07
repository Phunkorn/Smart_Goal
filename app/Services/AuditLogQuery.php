<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\AuditSnapshot;
use App\Support\TodayWorkspace;
use App\Support\TrashRestorers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * การกรองร่วมของหน้า Audit Log
 *
 * ทั้งสามแท็บใช้ตัวกรองชุดเดียวกัน (ช่วงเวลา ผู้ใช้ คำค้น) จึงต้องมีที่มาเดียว
 * มิฉะนั้นการสลับแท็บจะให้ผลลัพธ์ที่ไม่ตรงกันโดยที่ผู้ใช้ไม่รู้ตัว
 *
 * วันที่ที่ผู้ใช้กรอกเป็นเวลาไทย แต่คอลัมน์ในฐานข้อมูลเป็น UTC
 * การแปลงจึงเกิดที่ขอบของคิวรีเท่านั้น ตามกฎเรื่อง timezone ของโปรเจกต์
 */
class AuditLogQuery
{
    /** แท็บที่หน้านี้รองรับ ค่าที่ไม่รู้จักต้องตกกลับมาที่ภาพรวมเสมอ */
    public const TABS = ['overview', 'activity', 'trash'];

    public const DEFAULT_TAB = 'overview';

    public static function tab(Request $request): string
    {
        $tab = (string) $request->string('tab');

        return in_array($tab, self::TABS, true) ? $tab : self::DEFAULT_TAB;
    }

    /**
     * บันทึกกิจกรรมของผู้ใช้ รวมเหตุการณ์เข้าออกระบบ
     */
    public function activity(Request $request): Builder
    {
        $query = ActivityLog::with('user')
            ->when($request->filled('action'), fn ($inner) => $inner->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($inner) => $inner->where('user_id', $request->integer('user_id')))
            ->when($request->filled('subject_type'), fn ($inner) => $inner->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('q'), function ($inner) use ($request) {
                $search = '%'.$request->string('q').'%';
                $inner->where(function ($group) use ($search) {
                    $group->where('description', 'like', $search)
                        ->orWhere('subject_type', 'like', $search)
                        ->orWhere('action', 'like', $search)
                        ->orWhere('subject_id', 'like', $search)
                        ->orWhere('ip_address', 'like', $search)
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', $search)
                                ->orWhere('email', 'like', $search)
                                ->orWhere('username', 'like', $search);
                        });
                });
            });

        return $this->applyDateRange($query, $request, 'created_at');
    }

    /**
     * ข้อมูลที่ถูกลบและยังอยู่ในช่วงเก็บรักษา
     */
    public function trash(Request $request): Builder
    {
        $query = TrashLog::with('deletedBy')
            ->when($request->filled('entity_type'), fn ($inner) => $inner->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('deleted_by'), fn ($inner) => $inner->where('deleted_by', $request->integer('deleted_by')))
            // ตัวกรองผู้ใช้ร่วมของหน้านี้หมายถึง "ผู้ทำรายการ" ซึ่งในถังขยะคือผู้ลบ
            ->when($request->filled('user_id') && ! $request->filled('deleted_by'),
                fn ($inner) => $inner->where('deleted_by', $request->integer('user_id')))
            ->when($request->filled('department'), function ($inner) use ($request) {
                $department = '%'.$request->string('department').'%';
                $inner->where('payload_json', 'like', $department);
            })
            ->when($request->filled('q'), function ($inner) use ($request) {
                $search = '%'.$request->string('q').'%';
                $inner->where(function ($group) use ($search) {
                    $group->where('entity_type', 'like', $search)
                        ->orWhere('entity_id', 'like', $search)
                        ->orWhere('payload_json', 'like', $search);
                });
            });

        $this->applyTrashShortcut($query, $request);

        return $this->applyDateRange($query, $request, 'deleted_at');
    }

    /**
     * ตัวกรองลัดของแท็บถังขยะ
     *
     * คำถามที่ผู้ดูแลระบบถามบ่อยที่สุดคือ "อะไรกำลังจะหาย" กับ "อะไรกู้ไม่ได้แล้ว"
     * ทั้งสองอย่างเดิมต้องเดาจากคอลัมน์วันที่เอาเอง เพราะตัวกรองมีแต่ประเภทกับแผนก
     *
     * เป็นตัวกรองเดี่ยว ไม่ใช่หลายตัวพร้อมกัน เพราะเงื่อนไขขัดกันเอง
     * (หมดเวลาแล้วกับใกล้หมดเวลาเป็นคนละเซ็ตที่ไม่มีทางทับกัน)
     *
     * @param  Builder<TrashLog>  $query
     */
    private function applyTrashShortcut(Builder $query, Request $request): void
    {
        $shortcut = $request->string('shortcut')->toString();

        match ($shortcut) {
            // ใกล้ถูกลบถาวรภายใน 7 วัน — ต้องรีบตัดสินใจว่าจะกู้หรือปล่อย
            'expiring' => $query->whereNotNull('purge_after')
                ->whereBetween('purge_after', [now(), now()->addDays(7)]),

            // พ้นกำหนดแล้ว กดกู้คืนไม่ได้ เหลือแค่รอคำสั่งล้าง
            'expired' => $query->whereNotNull('purge_after')->where('purge_after', '<=', now()),

            // เฉพาะไฟล์และรูป ซึ่งเป็นสิ่งที่ผู้ใช้ตามหามากที่สุดเวลาลบผิด
            'files' => $query->whereIn('entity_type', TrashRestorers::FILE_OWNERS),

            default => null,
        };
    }

    /**
     * ตัวเลขสรุปของแท็บภาพรวม
     *
     * นับ "วันนี้" ตามวันเวลาไทย ไม่ใช่ UTC เพราะผู้ดูแลอ่านตัวเลขนี้เทียบกับวันทำงานจริง
     *
     * @return array<string, int>
     */
    public function overview(Request $request): array
    {
        $todayStart = CarbonImmutable::now(TodayWorkspace::BUSINESS_TIMEZONE)->startOfDay()->utc();

        $activityToday = fn () => ActivityLog::query()->where('created_at', '>=', $todayStart);
        $trashQuery = fn () => $this->trash($request);
        $nearExpiryCutoff = now()->copy()->addDays(7);

        return [
            'logins_today' => $activityToday()->where('action', 'login')->count(),
            'failed_logins_today' => $activityToday()->whereIn('action', ['login_failed', 'login_locked'])->count(),
            'changes_today' => $activityToday()->whereNotIn('action', AuditSnapshot::AUTH_ACTIONS)->count(),
            'trash_total' => $trashQuery()->count(),
            'near_expiry' => $trashQuery()
                ->where('purge_after', '>', now())
                ->where('purge_after', '<=', $nearExpiryCutoff)
                ->count(),
        ];
    }

    /**
     * ตัวเลขของแท็บถังขยะ คงรูปเดิมทุกคีย์เพื่อไม่ให้การ์ดและเทสต์ที่มีอยู่เปลี่ยนความหมาย
     *
     * @return array<string, int>
     */
    public function trashStats(Request $request): array
    {
        $now = now();
        $nearExpiryCutoff = $now->copy()->addDays(7);

        return [
            'total' => $this->trash($request)->count(),
            'work_items' => $this->trash($request)
                ->whereIn('entity_type', [WorkOrder::class, WorkOrderList::class])
                ->count(),
            'users' => $this->trash($request)->where('entity_type', User::class)->count(),
            'near_expiry' => $this->trash($request)
                ->where('purge_after', '>', $now)
                ->where('purge_after', '<=', $nearExpiryCutoff)
                ->count(),

            // ไฟล์และรูปที่กู้คืนได้ — เดิมไม่มีตัวเลขนี้เพราะกู้ไฟล์ไม่ได้เลย
            'files' => $this->trash($request)
                ->whereIn('entity_type', TrashRestorers::FILE_OWNERS)
                ->count(),
        ];
    }

    /**
     * จำนวนรายการที่พ้นกำหนดกู้คืนแล้วทั้งระบบ
     *
     * ไม่ผูกกับตัวกรองของหน้า เพราะปุ่ม "ล้างของหมดอายุ" ล้างทั้งระบบเสมอ
     * ตัวเลขที่ขึ้นบนปุ่มจึงต้องตรงกับสิ่งที่ปุ่มจะทำจริง ไม่ใช่ตรงกับสิ่งที่เห็นบนหน้าจอ
     */
    public function expiredTrashCount(): int
    {
        return TrashLog::whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->count();
    }

    /**
     * ผู้ใช้ที่เคยปรากฏในบันทึก รวมบัญชีที่ถูกลบไปแล้ว เพราะบันทึกของเขายังต้องตรวจสอบย้อนหลังได้
     */
    public function actorOptions()
    {
        return User::withTrashed()->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * แปลงวันที่แบบเวลาไทยเป็นช่วง UTC
     *
     * รับค่าเป็นวัน (Y-m-d) แล้วขยายเป็นทั้งวันตามเวลาไทย ผู้ใช้ที่เลือก "ถึง 31 ส.ค."
     * จึงได้รายการของวันที่ 31 ครบทั้งวัน ไม่ใช่ตัดที่เที่ยงคืนต้นวัน
     */
    private function applyDateRange(Builder $query, Request $request, string $column): Builder
    {
        $timezone = TodayWorkspace::BUSINESS_TIMEZONE;

        if ($request->filled('from')) {
            $from = $this->parseBusinessDate($request->string('from')->toString(), $timezone);
            if ($from) {
                $query->where($column, '>=', $from->startOfDay()->utc());
            }
        }

        if ($request->filled('to')) {
            $to = $this->parseBusinessDate($request->string('to')->toString(), $timezone);
            if ($to) {
                $query->where($column, '<=', $to->endOfDay()->utc());
            }
        }

        return $query;
    }

    private function parseBusinessDate(string $value, string $timezone): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
