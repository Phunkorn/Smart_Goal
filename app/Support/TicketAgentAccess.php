<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ใครเป็นเจ้าหน้าที่ (agent) ของ Smart Ticket และใครเห็น Ticket ทั้งหมด — แหล่งความจริงเดียว
 *
 * รูปแบบเดียวกับ ComputerCheckAccess: ที่นี่ตอบ "ข้อเท็จจริงร่วม" ที่ถูกถามจาก TicketPolicy
 * (บังคับสิทธิ์จริง), TicketQueryService (scope รายการ), เมนู และผู้รับแจ้งเตือน
 * ทุกที่ต้องได้คำตอบเดียวกัน ส่วนการบังคับใช้ยังอยู่ที่ TicketPolicy
 *
 * กติกา agent
 * ---------------------------------------------------------------
 * 1. ต้องเป็นบัญชี active และไม่ใช่ viewer (viewer เป็นผู้รับงานไม่ได้ตาม CLAUDE.md)
 * 2. มีแถวใน ticket_agents และ is_enabled ไม่เป็น null → ใช้ค่านั้น (admin ตั้งรายคน)
 * 3. ไม่มีแถว หรือ is_enabled เป็น null ("ตามแผนก") → เป็น agent เมื่อแผนกของตัวเอง
 *    ถูกตั้งเป็น ticket_responsible
 *
 * admin ไม่ได้เป็น agent อัตโนมัติ — การรับและปิดงานต้องถูกตั้งเป็น agent ก่อน
 * (CLAUDE.md ห้ามเพิ่ม admin bypass เมื่อธุรกิจไม่ได้ระบุ) แต่ admin เห็น Ticket ทั้งหมด
 * ตามข้อกำหนด "Admin ดู Ticket ทั้งหมด"
 */
final class TicketAgentAccess
{
    /**
     * บัญชีที่ใช้ Smart Ticket ภายในได้: active ไม่ถูกลบ และเป็น admin หรือ user
     *
     * ต้องตรวจ trashed() เอง เพราะความสัมพันธ์ของ Ticket โหลดผู้ใช้แบบ withTrashed()
     * (เพื่อแสดงชื่อในประวัติ) พนักงานที่ถูกลบแล้วจึงอาจมาถึงที่นี่ได้
     */
    public static function isStaff(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && ! $user->trashed()
            && in_array($user->role, ['admin', 'user'], true);
    }

    public static function isAgent(?User $user): bool
    {
        if (! self::isStaff($user)) {
            return false;
        }

        // โหลดครั้งเดียวต่อ instance — policy ถูกถามซ้ำทุกการ์ดในหน้ารายการ
        $user->loadMissing(['ticketAgent', 'department']);

        $override = $user->ticketAgent?->is_enabled;

        return $override ?? (bool) $user->department?->ticket_responsible;
    }

    /** สิทธิ์นี้มาจากไหน — ใช้แสดงในหน้าตั้งค่าของ admin */
    public static function source(User $user): string
    {
        $user->loadMissing('ticketAgent');

        return match ($user->ticketAgent?->is_enabled) {
            true => 'granted',
            false => 'revoked',
            null => 'department',
        };
    }

    /** รับแจ้งเตือน Ticket ใหม่หรือไม่ (ไม่มีแถว override = รับ) */
    public static function receivesAlerts(User $user): bool
    {
        $user->loadMissing('ticketAgent');

        return $user->ticketAgent?->receives_alerts ?? true;
    }

    /**
     * ได้ยินเสียงแจ้ง Ticket ใหม่ — agent ที่เปิดรับแจ้งเตือน
     * เงื่อนไขเดียวของทั้งตัวเล่นเสียงทุกหน้า (layouts.app) และปุ่มเปิด/ปิดเสียงบนหน้ารับแจ้งปัญหา
     */
    public static function hearsAlerts(?User $user): bool
    {
        return self::isAgent($user) && self::receivesAlerts($user);
    }

    /**
     * แจ้ง Ticket ได้ (Reporter) — แยกหน้าที่จากผู้รับงานเด็ดขาด
     *
     * พนักงานทั่วไป (role user) ที่ไม่ใช่ Handler และไม่ใช่หัวหน้าแผนก
     *   - Handler (isAgent: override รายคนของ admin > แผนกที่รับผิดชอบ) แจ้งไม่ได้ ไม่ว่าเป็นหัวหน้าแผนกหรือไม่
     *   - หัวหน้าแผนกของแผนกที่รับผิดชอบ Ticket (seesDepartmentTickets) แจ้งไม่ได้ — ดูรายการของแผนกแบบอ่านอย่างเดียวแทน
     *   - หัวหน้าแผนกอื่น (แผนกไม่ได้รับผิดชอบ Ticket) แจ้งได้เหมือนพนักงานทั่วไป — แผนกนั้นไม่ได้จัดการ Ticket
     *   - admin แจ้งไม่ได้ (ดูทั้งหมดและตั้งค่า แต่ไม่ใช่ผู้แจ้ง)
     * อ่านจากฐานข้อมูลทุกคำขอ admin เปลี่ยนสิทธิ์แล้วมีผลทันที — ไม่แตะ Ticket เดิมของบัญชีนั้น
     */
    public static function canReport(?User $user): bool
    {
        return self::isStaff($user)
            && $user->role === 'user'
            && ! self::seesDepartmentTickets($user)
            && ! self::isAgent($user);
    }

    /**
     * เห็นรายการ Ticket ของแผนกตัวเองแบบอ่านอย่างเดียว (ticket-queue panel ของหัวหน้าแผนก)
     *
     * เฉพาะหัวหน้าแผนกที่ "แผนกของตัวเองรับผิดชอบ Ticket" (departments.ticket_responsible)
     * หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket (เช่น การตลาด) ไม่มีส่วนเกี่ยวข้องกับคิวงาน
     * จึงเป็นผู้แจ้งธรรมดา — ไม่เห็นเมนู/หน้ารายการของแผนก และไม่เห็นใบของสมาชิกแผนก
     *
     * ตามปกติหัวหน้าแผนกที่รับผิดชอบ Ticket จะเป็น agent อยู่แล้วและเห็นทั้งหมด (seesAllTickets)
     * ที่เหลือคือกรณีที่ admin ปิดสิทธิ์ Handler รายคนไว้ — ยังดูของแผนกตัวเองได้
     */
    public static function seesDepartmentTickets(?User $user): bool
    {
        if (! self::isStaff($user) || ! $user->isDepartmentHead()) {
            return false;
        }

        $user->loadMissing('department');

        return (bool) $user->department?->ticket_responsible;
    }

    /** เห็น Ticket ทุกใบ: admin และ agent */
    public static function seesAllTickets(?User $user): bool
    {
        return self::isStaff($user)
            && ($user->role === 'admin' || self::isAgent($user));
    }

    /**
     * คิวรีผู้ใช้ที่เป็น agent — นิยามเดียวกับ isAgent() ในรูป SQL
     * ใช้กับหน้าตั้งค่าและการหาผู้รับแจ้งเตือน (test ตรึงว่าสองทางตอบตรงกัน)
     */
    public static function agentsQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'user'])
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('ticketAgent', fn (Builder $agent) => $agent->where('is_enabled', true))
                    ->orWhere(function (Builder $byDepartment): void {
                        $byDepartment
                            ->whereDoesntHave('ticketAgent', fn (Builder $agent) => $agent->whereNotNull('is_enabled'))
                            ->whereHas('department', fn (Builder $department) => $department->where('ticket_responsible', true));
                    });
            });
    }

    /** agent ที่รับแจ้งเตือน Ticket ใหม่ (ไม่มีแถว override = รับ) */
    public static function alertRecipientsQuery(): Builder
    {
        return self::agentsQuery()->where(function (Builder $query): void {
            $query
                ->whereDoesntHave('ticketAgent')
                ->orWhereHas('ticketAgent', fn (Builder $agent) => $agent->where('receives_alerts', true));
        });
    }
}
