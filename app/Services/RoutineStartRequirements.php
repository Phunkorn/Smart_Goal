<?php

namespace App\Services;

use RuntimeException;

/**
 * กดเริ่มงานประจำแล้วยังขาดคำตอบที่ต้องมีก่อน
 *
 * - backlog: วันที่ค้างของงานเดียวกันที่ยังไม่มีเหตุผล (ต้องตอบครบทุกวันในครั้งเดียว)
 * - attendance: ผู้ร่วมงานคนอื่นที่วันนี้ยังไม่ได้เริ่ม ต้องตอบว่ามาหรือไม่มา
 *   แต่ละคนมี backlog ของตัวเอง ต้องตอบแทนเมื่อเลือกว่า "มาทำด้วย" (คนนั้นเริ่มพร้อมกันทันที)
 *
 * ผู้เรียกแบบ AJAX ได้รายการทั้งสองกลับไปแสดงใน Swal แล้วส่งคำตอบมาที่ endpoint เดิม
 */
final class RoutineStartRequirements extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $backlog
     * @param  list<array<string, mixed>>  $attendance
     */
    public function __construct(public readonly array $backlog, public readonly array $attendance)
    {
        $parts = [];

        if ($backlog !== []) {
            $parts[] = sprintf('ระบุเหตุผลของวันที่ค้าง %d วัน', count($backlog));
        }

        if ($attendance !== []) {
            $parts[] = 'ตอบว่าผู้ร่วมงานมาทำด้วยหรือไม่';
        }

        if (collect($attendance)->contains(fn (array $member): bool => ($member['backlog'] ?? []) !== [])) {
            $parts[] = 'ระบุเหตุผลวันค้างของผู้ร่วมงานที่มาทำด้วย';
        }

        parent::__construct('ก่อนเริ่มงานต้อง'.implode(' และ', $parts));
    }
}
