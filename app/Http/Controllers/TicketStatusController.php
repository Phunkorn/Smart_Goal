<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketStatusConflict;
use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Tickets\UpdateTicketStatusRequest;
use App\Models\Ticket;
use App\Services\TicketWorkflowService;
use App\Support\TicketDesign;

/**
 * เปลี่ยนสถานะ Ticket ผ่าน TicketWorkflowService::transition() ทางเดียว
 *
 * ถ้าเจ้าหน้าที่อีกคนเปลี่ยนสถานะไปก่อน (expected_status ไม่ตรง) ตอบ 409 พร้อมข้อความ
 * ที่บอกสถานะล่าสุด — หน้าที่ถูกโหลดใหม่จาก back() จึงแสดงสถานะจริงพร้อมคำอธิบาย
 */
class TicketStatusController extends Controller
{
    use RespondsWithTaskResult;

    public function update(UpdateTicketStatusRequest $request, Ticket $ticket, TicketWorkflowService $workflow)
    {
        $action = $request->validated('action');

        try {
            $updated = $workflow->transition(
                $ticket,
                $action,
                $request->user(),
                $request->validated('expected_status'),
                [...$request->safe()->only(['note', 'follow_up_date']), 'photos' => $request->photos()],
            );
        } catch (TicketStatusConflict $conflict) {
            return $this->jsonOrBack(
                $request,
                false,
                'มีเจ้าหน้าที่คนอื่นเปลี่ยนสถานะเป็น "'.TicketDesign::status($conflict->currentStatus)['label']
                    .'" ไปก่อนแล้ว หน้านี้แสดงสถานะล่าสุดให้แล้ว กรุณาตรวจสอบอีกครั้ง',
                409,
                ['status' => $conflict->currentStatus],
            );
        }

        return $this->jsonOrBack(
            $request,
            true,
            TicketDesign::actionLabel($action).'แล้ว — สถานะปัจจุบัน: '.TicketDesign::status($updated->status)['label'],
            200,
            ['status' => $updated->status],
        );
    }
}
