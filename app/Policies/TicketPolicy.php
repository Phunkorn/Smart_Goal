<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketAgentAccess;
use Illuminate\Auth\Access\Response;

/**
 * สิทธิ์ของ Smart Ticket — ผู้ตัดสินจริงเพียงที่เดียว
 *
 * การมองเห็น (ต้องตรงกับ TicketQueryService::visibleTo() เสมอ)
 * ---------------------------------------------------------------
 *   - viewer / บัญชีปิดใช้งาน: ไม่เห็นอะไรเลย
 *   - admin และ agent: ทุกใบ
 *   - พนักงาน: ใบที่ตัวเองแจ้ง (อ่านอย่างเดียวผ่าน modal ใน /tickets)
 *   - หัวหน้าแผนกของแผนกที่รับผิดชอบ Ticket (TicketAgentAccess::seesDepartmentTickets):
 *     ใบที่ตัวเองแจ้ง + ใบที่สมาชิกแผนกตัวเองแจ้ง (อ่านอย่างเดียว)
 *     หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket เป็นผู้แจ้งธรรมดา — เห็นเฉพาะใบที่ตัวเองแจ้ง
 *     อ้างอิงแผนกจาก snapshot requester_department_id ตอนแจ้ง ไม่ใช่แผนกปัจจุบันของผู้แจ้ง
 *     Ticket จากฟอร์มสาธารณะไม่มีแผนก หัวหน้าแผนกจึงไม่เห็น
 *
 * แยกหน้าที่ผู้แจ้ง (Reporter) กับผู้รับงาน (Handler) เด็ดขาด
 * ---------------------------------------------------------------
 *   - แจ้ง Ticket: TicketAgentAccess::canReport — พนักงานที่ไม่ใช่ Handler และไม่ได้ดูคิวของแผนก (admin แจ้งไม่ได้)
 *   - Handler จัดการ Ticket ที่ตัวเองเป็นผู้แจ้งไม่ได้ (handles()) — เช่นผู้ที่มีเคสค้างก่อนถูกตั้งเป็น Handler
 *     ยกเว้น "ออกจาก Ticket" ที่ยังทำได้ เพื่อถอนตัวจากเคสเดิม
 *   - viewInternal: บันทึกการดำเนินงาน รูปของบันทึก และหน้ารายละเอียดเต็ม — เฉพาะผู้ที่เห็นคิวงาน (admin / Handler)
 *     ผู้แจ้งและหัวหน้าแผนกเห็นเฉพาะสถานะ รายละเอียดที่แจ้ง และผลการแก้ไข (components/readonly-preview)
 *
 * การดำเนินงาน (ทุกข้อต้องผ่าน handles() — agent ที่ไม่ใช่ผู้แจ้งใบนั้น)
 * ---------------------------------------------------------------
 *   - รับงาน / เปิดงานใหม่: agent — คนที่กดรับงานเข้าร่วม Ticket อัตโนมัติเป็นคนแรก (TicketWorkflowService)
 *   - เข้าร่วม: agent เมื่อ Ticket ถูกรับงานแล้วเท่านั้น (ไม่ใช่ "รอรับงาน" และยังไม่ปิด)
 *   - ติดตาม / กลับมาดำเนินการ / ปิดงาน / ออก / ตั้งผู้ประสานงาน: agent ที่เป็นผู้เข้าร่วมปัจจุบัน
 *     ไม่มีผู้ถือ Ticket แต่เพียงผู้เดียว — agent คนใดก็เข้าร่วมได้ด้วยคลิกเดียวแล้วทำได้เท่ากัน
 *   - บันทึกงาน: ผู้เข้าร่วมปัจจุบันที่เป็น agent ขณะที่ Ticket ยังไม่ปิด (ผู้แจ้งเพิ่มบันทึกหลังส่งไม่ได้)
 *
 * ตั้งใจไม่มี admin bypass บนการดำเนินงาน — admin ที่จะรับงานต้องถูกตั้งเป็น agent ก่อน
 * สถานะที่ทำได้อ่านจาก Ticket::TRANSITIONS ที่เดียวกับ TicketWorkflowService
 */
class TicketPolicy
{
    public const JOIN_NEEDS_START = 'Ticket นี้ยังไม่มีผู้รับงาน — ต้องกด "รับงาน" ก่อน แล้วเจ้าหน้าที่คนอื่นจึงเข้าร่วมได้';

    public function viewAny(User $user): bool
    {
        return TicketAgentAccess::isStaff($user);
    }

    public function create(User $user): bool
    {
        return TicketAgentAccess::canReport($user);
    }

    /** บันทึกการดำเนินงาน รูปของบันทึก และหน้ารายละเอียดเต็ม — ผู้ที่เห็นคิวงานทั้งหมด (admin / Handler) */
    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return TicketAgentAccess::seesAllTickets($user);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if (! TicketAgentAccess::isStaff($user)) {
            return false;
        }

        if (TicketAgentAccess::seesAllTickets($user) || $ticket->isRequestedBy($user)) {
            return true;
        }

        return $ticket->requester_department_id !== null
            && TicketAgentAccess::seesDepartmentTickets($user)
            && $user->overseesDepartment($ticket->requester_department_id);
    }

    /** Ticket ที่ยังรอรับงานเข้าร่วมไม่ได้ — ข้อความปฏิเสธถูกแสดงให้ผู้ใช้ (TicketParticipantController::store) */
    public function join(User $user, Ticket $ticket): Response
    {
        if ($ticket->isClosed() || ! $this->handles($user, $ticket)) {
            return Response::deny();
        }

        if ($ticket->status === Ticket::STATUS_NEW) {
            return Response::deny(self::JOIN_NEEDS_START);
        }

        return Response::allow();
    }

    public function leave(User $user, Ticket $ticket): bool
    {
        return $this->isWorkingOn($user, $ticket);
    }

    public function setCoordinator(User $user, Ticket $ticket): bool
    {
        return $this->isWorkingOn($user, $ticket) && $this->handles($user, $ticket);
    }

    public function start(User $user, Ticket $ticket): bool
    {
        return $this->handles($user, $ticket)
            && Ticket::allowsTransition($ticket->status, Ticket::ACTION_START);
    }

    public function followUp(User $user, Ticket $ticket): bool
    {
        return $this->canTransitionAsParticipant($user, $ticket, Ticket::ACTION_FOLLOW_UP);
    }

    public function resume(User $user, Ticket $ticket): bool
    {
        return $this->canTransitionAsParticipant($user, $ticket, Ticket::ACTION_RESUME);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $this->canTransitionAsParticipant($user, $ticket, Ticket::ACTION_CLOSE);
    }

    public function cancel(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === Ticket::STATUS_NEW) {
            return $this->handles($user, $ticket);
        }

        return $this->canTransitionAsParticipant($user, $ticket, Ticket::ACTION_CANCEL);
    }

    public function reopen(User $user, Ticket $ticket): bool
    {
        return $this->handles($user, $ticket)
            && Ticket::allowsTransition($ticket->status, Ticket::ACTION_REOPEN);
    }

    public function addNote(User $user, Ticket $ticket): bool
    {
        return $this->isWorkingOn($user, $ticket) && $this->handles($user, $ticket);
    }

    public function viewReport(User $user): bool
    {
        return TicketAgentAccess::seesAllTickets($user);
    }

    public function manageSettings(User $user): bool
    {
        return TicketAgentAccess::isStaff($user) && $user->role === 'admin';
    }

    /** ชื่อ ability ของแต่ละ action — ให้ service และ controller ใช้ชุดเดียวกัน */
    public static function abilityFor(string $action): string
    {
        return match ($action) {
            Ticket::ACTION_START => 'start',
            Ticket::ACTION_FOLLOW_UP => 'followUp',
            Ticket::ACTION_RESUME => 'resume',
            Ticket::ACTION_CLOSE => 'close',
            Ticket::ACTION_CANCEL => 'cancel',
            Ticket::ACTION_REOPEN => 'reopen',
        };
    }

    /** Handler ที่ไม่ใช่ผู้แจ้งใบนี้ — แยกหน้าที่ผู้แจ้งกับผู้รับงาน */
    private function handles(User $user, Ticket $ticket): bool
    {
        return TicketAgentAccess::isAgent($user) && ! $ticket->isRequestedBy($user);
    }

    /** agent ที่เป็นผู้เข้าร่วมปัจจุบันของ Ticket ที่ยังไม่ปิด */
    private function isWorkingOn(User $user, Ticket $ticket): bool
    {
        return ! $ticket->isClosed()
            && TicketAgentAccess::isAgent($user)
            && $ticket->hasActiveParticipant($user);
    }

    private function canTransitionAsParticipant(User $user, Ticket $ticket, string $action): bool
    {
        return Ticket::allowsTransition($ticket->status, $action)
            && $this->isWorkingOn($user, $ticket)
            && $this->handles($user, $ticket);
    }
}
