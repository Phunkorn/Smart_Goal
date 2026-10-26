<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Tickets\UpdateTicketCoordinatorRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\TicketParticipantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * เข้าร่วม ออก และตั้งผู้ประสานงาน — สิทธิ์ตรวจใน TicketParticipantService กับแถวที่ล็อกไว้
 * (TicketPolicy::join / leave / setCoordinator) เพื่อตัดสินจากสถานะล่าสุดเสมอ
 */
class TicketParticipantController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly TicketParticipantService $participants) {}

    /**
     * Ticket ที่ยังไม่มีผู้รับงาน → บอกให้กดรับงานก่อน (AJAX: 403 + ข้อความ, form ปกติ: กลับหน้าเดิมพร้อมข้อความ)
     * การปฏิเสธอื่น (ไม่ใช่ agent, ปิดแล้ว) ยังเป็น 403 ตามปกติ
     */
    public function store(Request $request, Ticket $ticket)
    {
        try {
            $joined = $this->participants->join($ticket, $request->user());
        } catch (AuthorizationException $denied) {
            if ($denied->getMessage() !== TicketPolicy::JOIN_NEEDS_START) {
                throw $denied;
            }

            return $this->jsonOrBack($request, false, $denied->getMessage(), 403);
        }

        return $this->jsonOrBack($request, true, $joined ? 'เข้าร่วม Ticket แล้ว' : 'คุณเข้าร่วม Ticket นี้อยู่แล้ว');
    }

    public function destroy(Request $request, Ticket $ticket)
    {
        $this->participants->leave($ticket, $request->user());

        return $this->jsonOrBack($request, true, 'ออกจาก Ticket แล้ว ประวัติการทำงานของคุณยังอยู่ใน timeline');
    }

    public function coordinator(UpdateTicketCoordinatorRequest $request, Ticket $ticket)
    {
        $coordinatorId = $request->validated('coordinator_id');
        $coordinator = $coordinatorId ? User::query()->find($coordinatorId) : null;

        $changed = $this->participants->setCoordinator($ticket, $request->user(), $coordinator);

        $message = match (true) {
            ! $changed => 'ผู้ประสานงานไม่มีการเปลี่ยนแปลง',
            $coordinator === null => 'ยกเลิกผู้ประสานงานแล้ว',
            default => 'ตั้ง '.$coordinator->name.' เป็นผู้ประสานงานแล้ว',
        };

        return $this->jsonOrBack($request, true, $message);
    }
}
