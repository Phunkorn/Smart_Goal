<?php

namespace App\Support;

use App\Models\User;

/**
 * ข้อความที่ใช้กับคำขออนุมัติงานข้ามแผนกและผู้ร่วมงานข้ามแผนก
 *
 * เดิมทุกจุดฮาร์ดโค้ดคำว่า "ผู้ดูแลระบบ" และแสดงสถานะเป็นคำอังกฤษดิบ ("pending")
 * ทั้งที่ผู้อนุมัติจริงส่วนใหญ่คือหัวหน้าแผนกปลายทาง ไม่ใช่ admin
 * (ดู WorkOrderPolicy::approve() และ NotificationService::departmentApprovalRecipientIds())
 * ผลคือผู้ขอได้แจ้งเตือนว่า "ผู้ดูแลระบบอนุมัติ..." ทั้งที่หัวหน้าแผนกเป็นคนกด
 *
 * รวมไว้ที่เดียวเพื่อให้ Blade, NotificationService, AuditTrail และ controller
 * พูดตรงกันเสมอ
 */
class ApprovalPresenter
{
    private const STATUS_LABELS = [
        'pending' => 'รอตรวจสอบ',
        'approved' => 'อนุมัติแล้ว',
        'accepted' => 'ตอบรับแล้ว',
        'rejected' => 'ถูกปฏิเสธ',
    ];

    private const STATUS_TONES = [
        'pending' => 'warning',
        'approved' => 'success',
        'accepted' => 'success',
        'rejected' => 'danger',
    ];

    /**
     * ระบุว่าใครเป็นผู้ตัดสิน พร้อมบทบาทที่ให้อำนาจนั้น
     * เช่น "พันกร ศรีทอน (หัวหน้าแผนกไอที)" หรือ "สมชาย (ผู้ดูแลระบบ)"
     */
    public static function approverLabel(?User $actor): string
    {
        if (! $actor) {
            return 'ผู้ดูแลระบบ';
        }

        return $actor->name.' ('.self::roleLabel($actor).')';
    }

    public static function roleLabel(?User $actor): string
    {
        if (! $actor) {
            return 'ผู้ดูแลระบบ';
        }

        if ($actor->role === 'admin') {
            return 'ผู้ดูแลระบบ';
        }

        if ($actor->isDepartmentHead()) {
            $department = $actor->department?->department_name;

            return $department ? 'หัวหน้าแผนก'.$department : 'หัวหน้าแผนก';
        }

        return 'ผู้อนุมัติ';
    }

    /**
     * คำกริยาที่ใช้เล่าการตัดสินใจ ต่างจาก statusLabel() ที่เป็นคำนามสำหรับป้ายสถานะ
     */
    public static function decisionVerb(string $decision): string
    {
        return in_array($decision, ['approved', 'accepted'], true) ? 'อนุมัติ' : 'ปฏิเสธ';
    }

    public static function statusLabel(?string $approvalStatus): string
    {
        return self::STATUS_LABELS[$approvalStatus] ?? 'รอตรวจสอบ';
    }

    public static function statusTone(?string $approvalStatus): string
    {
        return self::STATUS_TONES[$approvalStatus] ?? 'warning';
    }
}
