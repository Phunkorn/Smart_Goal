<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Tickets\StoreTicketNoteRequest;
use App\Models\Ticket;
use App\Services\TicketNoteService;

/**
 * บันทึกการดำเนินงาน (ข้อความ + รูป) — ตรวจสิทธิ์และบันทึกใน TicketNoteService
 */
class TicketNoteController extends Controller
{
    use RespondsWithTaskResult;

    public function store(StoreTicketNoteRequest $request, Ticket $ticket, TicketNoteService $notes)
    {
        $notes->add($ticket, $request->user(), $request->validated('body'), $request->photos());

        return $this->jsonOrBack($request, true, 'บันทึกการดำเนินงานแล้ว', 201);
    }
}
