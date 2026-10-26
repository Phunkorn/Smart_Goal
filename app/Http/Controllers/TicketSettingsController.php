<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Tickets\Settings\AddTicketAgentRequest;
use App\Http\Requests\Tickets\Settings\SaveTicketLocationRequest;
use App\Http\Requests\Tickets\Settings\UpdateTicketAgentRequest;
use App\Http\Requests\Tickets\Settings\UpdateTicketDepartmentRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketLocation;
use App\Models\User;
use App\Services\TicketSettingsService;
use Illuminate\View\View;

/**
 * หน้าตั้งค่า Smart Ticket ของ admin — สิทธิ์: middleware admin + TicketPolicy::manageSettings
 * (Form Request ของทุก action) การเขียนค่าและ AuditTrail อยู่ที่ TicketSettingsService
 */
class TicketSettingsController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly TicketSettingsService $settings) {}

    public function index(): View
    {
        $this->authorize('manageSettings', Ticket::class);

        return view('admin.tickets.index', $this->settings->overview());
    }

    public function updateDepartment(UpdateTicketDepartmentRequest $request, Department $department)
    {
        $responsible = $request->boolean('ticket_responsible');
        $changed = $this->settings->setDepartmentResponsible($department, $responsible);

        return $this->jsonOrBack($request, true, ! $changed
            ? 'ไม่มีการเปลี่ยนแปลง'
            : ($responsible
                ? 'ตั้งแผนก '.$department->department_name.' เป็นผู้รับผิดชอบ Ticket แล้ว'
                : 'ยกเลิกแผนก '.$department->department_name.' จากผู้รับผิดชอบ Ticket แล้ว'));
    }

    public function storeAgent(AddTicketAgentRequest $request)
    {
        $user = User::query()->findOrFail($request->validated('user_id'));
        $this->settings->setAgent($user, true, true);

        return $this->jsonOrBack($request, true, 'เพิ่ม '.$user->name.' เป็นเจ้าหน้าที่ Ticket แล้ว', 201);
    }

    public function updateAgent(UpdateTicketAgentRequest $request, User $user)
    {
        $changed = $this->settings->setAgent($user, $request->enabled(), $request->boolean('receives_alerts'));

        return $this->jsonOrBack($request, true, $changed
            ? 'บันทึกสิทธิ์ของ '.$user->name.' แล้ว'
            : 'ไม่มีการเปลี่ยนแปลง');
    }

    public function storeLocation(SaveTicketLocationRequest $request)
    {
        $location = $this->settings->addLocation($request->validated('name'));

        return $this->jsonOrBack($request, true, 'เพิ่มสาขา '.$location->name.' แล้ว', 201);
    }

    public function updateLocation(SaveTicketLocationRequest $request, TicketLocation $location)
    {
        $changed = $this->settings->updateLocation(
            $location,
            $request->validated('name'),
            $request->boolean('is_active', $location->is_active),
        );

        return $this->jsonOrBack($request, true, $changed ? 'บันทึกสาขาแล้ว' : 'ไม่มีการเปลี่ยนแปลง');
    }
}
