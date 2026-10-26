<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\TicketReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** รายงาน Smart Ticket — admin และเจ้าหน้าที่ IT (TicketPolicy::viewReport) */
class TicketReportController extends Controller
{
    public function __invoke(Request $request, TicketReportService $reports): View
    {
        $this->authorize('viewReport', Ticket::class);

        return view('reports.tickets', $reports->build($request));
    }
}
