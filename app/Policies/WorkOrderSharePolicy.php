<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrderShare;

/**
 * สิทธิ์รอบ ๆ ประกาศแชร์งาน
 *
 * แยกจาก WorkOrderPolicy เพราะเป็นสิทธิ์ของ "ประกาศ" ไม่ใช่ของ "งาน" — คนที่เห็น
 * ประกาศยังไม่มีสิทธิ์เปิดงานนั้น (WorkOrderPolicy::view() ยังปฏิเสธอยู่จนกว่าจะ
 * เข้าร่วมสำเร็จ) หน้าแชร์งานจึงแสดงได้เฉพาะข้อมูลที่ประกาศเปิดเผยเท่านั้น
 */
class WorkOrderSharePolicy
{
    /**
     * เข้าหน้าแชร์งานได้หรือไม่
     *
     * viewer เป็นสิทธิ์อ่านอย่างเดียวและเป็นผู้ร่วมงานไม่ได้ จึงไม่มีอะไรให้ทำในหน้านี้
     */
    public function viewAny(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    public function view(User $user, WorkOrderShare $share): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin' || (int) $share->shared_by === (int) $user->id) {
            return true;
        }

        if ($share->scope === WorkOrderShare::SCOPE_ORGANIZATION) {
            return true;
        }

        return $share->department_id !== null
            && (int) $share->department_id === (int) $user->department_id;
    }

    /**
     * กดปุ่ม "ร่วมงาน" ได้หรือไม่
     *
     * เงื่อนไขที่เหลือ (เป็นผู้ร่วมงานอยู่แล้ว มีคำขอค้างอยู่แล้ว งานปิดแล้ว) ตรวจใน
     * WorkOrderShareService เพราะต้องอ่านฐานข้อมูลและต้องตรวจซ้ำบนแถวที่ล็อกแล้ว
     */
    public function requestJoin(User $user, WorkOrderShare $share): bool
    {
        // admin ไม่ผูกกับแผนกและมีสิทธิ์เข้าถึงงานทุกใบอยู่แล้ว การให้ขอเข้าร่วม
        // จึงไม่มีความหมาย และ CollaboratorInvitationService ก็รับเฉพาะ role = user
        return $user->role === 'user'
            && $user->is_active
            && $share->isOpen()
            && (int) $share->shared_by !== (int) $user->id
            && $this->view($user, $share);
    }

    /** ตัดสินคำขอเข้าร่วมของประกาศนี้ */
    public function review(User $user, WorkOrderShare $share): bool
    {
        return $user->role === 'admin' || (int) $share->shared_by === (int) $user->id;
    }

    public function close(User $user, WorkOrderShare $share): bool
    {
        return $this->review($user, $share);
    }

    /**
     * ตัดสินคำขอชั้นที่สองในฐานะหัวหน้าแผนกของงาน
     *
     * ชั้นนี้เกิดเฉพาะเมื่อผู้แชร์เป็นพนักงานธรรมดาและผู้ขออยู่ต่างแผนก ผู้ตัดสินคือ
     * หัวหน้าแผนกที่ดูแลแผนกปลายทางของ "งาน" ไม่ใช่หัวหน้าแผนกของผู้ขอ เพราะงานเป็น
     * ของแผนกนั้น คนที่ต้องรับผิดชอบว่าจะรับคนนอกเข้ามาหรือไม่จึงเป็นหัวหน้าแผนกนั้น
     */
    public function reviewAsHead(User $user, WorkOrderShare $share): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $task = $share->relationLoaded('workOrder') ? $share->workOrder : $share->workOrder()->first();

        if (! $task) {
            return false;
        }

        $task->loadMissing('user');

        return $user->overseesDepartment($task->department_id ?: $task->user?->department_id);
    }
}
