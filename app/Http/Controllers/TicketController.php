<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Models\Ticket;
use App\Models\TicketLocation;
use App\Models\User;
use App\Services\TicketQueryService;
use App\Services\TicketSubmissionService;
use App\Support\TicketAgentAccess;
use App\Support\TicketDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Smart Ticket ของผู้ใช้ภายใน: รายการ หน้ารายละเอียด และฟอร์มแจ้งปัญหา
 *
 * สิทธิ์ตัดสินที่ TicketPolicy ส่วนขอบเขตรายการมาจาก TicketQueryService::visibleTo()
 * ที่ใช้กติกาเดียวกัน การดำเนินงานแต่ละอย่างอยู่ใน controller ของตัวเอง
 * (TicketParticipantController, TicketStatusController, TicketNoteController)
 *
 * แยกหน้าที่ผู้แจ้ง (Reporter) กับผู้รับงาน (Handler) — ดู TicketAgentAccess::canReport
 */
class TicketController extends Controller
{
    /** ปุ่มบนหน้ารายละเอียด — คำนวณจาก policy ฝั่ง server ไม่ตัดสินใน JavaScript */
    private const CAPABILITIES = ['join', 'leave', 'setCoordinator', 'start', 'followUp', 'resume', 'close', 'cancel', 'reopen', 'addNote'];

    /** จำนวนใบที่แสดงใน modal "ประวัติ Ticket" ของผู้แจ้ง — ใบที่เก่ากว่านี้ยังเปิดดูได้จากลิงก์ ?ticket */
    private const REPORTER_HISTORY_LIMIT = 20;

    /**
     * หน้าเดียวกัน สามประสบการณ์ ตามความสามารถจริงของบัญชี (ไม่ใช่ชื่อแผนก):
     * - เห็น Ticket ทั้งหมด (admin / Handler) → คิวงานของทีม IT (ไม่มีปุ่มแจ้งปัญหา)
     * - หัวหน้าแผนกที่ไม่ใช่ Handler → รายการ Ticket ของแผนกแบบอ่านอย่างเดียว
     * - ผู้แจ้ง → การ์ดสถานะ Ticket ของตัวเอง (แจ้งได้เมื่อไม่มีใบที่ยังไม่ปิด)
     * ?ticket={id} เปิด modal อ่านอย่างเดียวของใบนั้นเมื่อ TicketPolicy::view อนุญาต (ลิงก์แจ้งเตือน / redirect จาก show)
     */
    public function index(Request $request, TicketQueryService $tickets, TicketSubmissionService $submissions): View|Response
    {
        $this->authorize('viewAny', Ticket::class);

        $user = $request->user();

        if (TicketAgentAccess::seesAllTickets($user)) {
            return view('tickets.index', $this->listData($request, $tickets, $user) + [
                'isAgent' => TicketAgentAccess::isAgent($user),
                // ปุ่มเปิด/ปิดเสียงแจ้ง Ticket ใหม่ — ตัวเล่นเสียงเองโหลดทุกหน้าจาก layouts.app ด้วยเงื่อนไขเดียวกัน
                'ticketSoundToggle' => TicketAgentAccess::hearsAlerts($user),
                'overview' => $tickets->overview(),
                'createdToday' => $tickets->createdToday(),
            ]);
        }

        if ($user->isDepartmentHead()) {
            $list = $this->listData($request, $tickets, $user);

            // รายการแผนกไม่มีการ์ดภาพรวม (ตัวเลขทั้งระบบ) จึงกรองสถานะด้วยแท็บที่นับตามขอบเขตของแผนก
            return view('tickets.department', $list + [
                'statusCounts' => $tickets->statusCounts($user, $list['filters']),
                'deepLinkTicket' => $this->deepLinkTicket($request),
            ]);
        }

        return $this->reporterPage($request, $submissions);
    }

    public function create(Request $request, TicketSubmissionService $submissions): View|RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        // มีใบที่ยังไม่ปิด → หน้าแรกแสดงสถานะใบนั้นแทนฟอร์ม
        if ($submissions->openTicketForRequester($request->user()->id)) {
            return redirect()->route('tickets.index');
        }

        return view('tickets.create', $this->submissionForm($request));
    }

    /** ส่งสำเร็จ → กลับ /tickets ที่การ์ดแสดง "รอเจ้าหน้าที่รับงาน" จากสถานะจริงในฐานข้อมูล */
    public function store(StoreTicketRequest $request, TicketSubmissionService $tickets): RedirectResponse
    {
        $ticket = $tickets->submitInternal($request->user(), $request->validated(), $request->photos());

        return redirect()
            ->route('tickets.index')
            ->with('success', 'ส่งแจ้งปัญหาเรียบร้อยแล้ว หมายเลข '.$ticket->ticket_number
                .' — ทีม IT ได้รับแจ้งแล้ว และคุณจะได้รับแจ้งเตือนเมื่อสถานะเปลี่ยน');
    }

    /**
     * หน้ารายละเอียดเต็มเป็นของผู้ที่เห็นคิวงาน (admin / Handler)
     * ผู้แจ้งและหัวหน้าแผนกที่เปิดลิงก์ตรงถูกพากลับ /tickets แล้วเปิด modal อ่านอย่างเดียว (view ตรวจซ้ำที่ index)
     */
    public function show(Request $request, Ticket $ticket): View|RedirectResponse
    {
        $this->authorize('view', $ticket);

        $user = $request->user();

        if (! $user->can('viewInternal', $ticket)) {
            return redirect()->route('tickets.index', ['ticket' => $ticket->id]);
        }

        $this->loadDetail($ticket);

        $can = collect(self::CAPABILITIES)->mapWithKeys(fn (string $ability) => [$ability => Gate::allows($ability, $ticket)]);

        // ผู้ประสานงานเลือกได้เฉพาะผู้เข้าร่วมปัจจุบันที่ยังเป็น agent (ตรงกับที่ service ตรวจ)
        $coordinatorCandidates = $ticket->participants
            ->filter(fn ($participant) => $participant->isActive() && $participant->user && TicketAgentAccess::isAgent($participant->user))
            ->values();

        return view('tickets.show', [
            'ticket' => $ticket,
            'can' => $can,
            // ปุ่มบันทึกการดำเนินงานอยู่ในการ์ดการดำเนินการด้วย (components/actions)
            'hasActions' => $can->contains(true),
            'coordinatorCandidates' => $coordinatorCandidates,
            'isParticipant' => $ticket->hasActiveParticipant($user),
        ]);
    }

    /**
     * เนื้อหา modal ดูรายละเอียด (HTML ล้วน ไม่มีเลย์เอาต์) — tickets/desk.js โหลดมาใส่ modal
     * ผู้ที่เห็นคิวงาน: component ชุดเดียวกับหน้ารายละเอียด + ปุ่มเข้าร่วม
     * หัวหน้าแผนก/ผู้แจ้ง: readonly-preview — สถานะ รายละเอียดที่แจ้ง และผลการแก้ไข ไม่มีบันทึกภายในหรือปุ่มใด
     */
    public function preview(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $user = $request->user();

        if (! $user->can('viewInternal', $ticket)) {
            return view('tickets.components.readonly-preview', ['ticket' => $this->loadReadonly($ticket), 'asModalContent' => true]);
        }

        $this->loadDetail($ticket);

        return view('tickets.components.preview', [
            'ticket' => $ticket,
            'canJoin' => $user->can('join', $ticket) && ! $ticket->hasActiveParticipant($user),
        ]);
    }

    /**
     * การ์ดสถานะของผู้แจ้ง — ตามใบที่ยังไม่ปิด (ไม่มี → ปุ่มแจ้งปัญหา)
     * และ modal "ประวัติ Ticket" ที่รวมใบของตัวเองทุกสถานะ (เฉพาะที่แจ้งผ่าน Smart Goals ของผู้ใช้คนนี้)
     */
    private function reporterPage(Request $request, TicketSubmissionService $submissions): Response
    {
        $user = $request->user();
        $open = $submissions->openTicketForRequester($user->id);
        $ownTickets = Ticket::query()
            ->where('source', Ticket::SOURCE_INTERNAL)
            ->where('requester_id', $user->id);

        // modal เปิดเองเมื่อมาจากลิงก์ (?ticket) — ใบอื่นที่ผู้แจ้งเห็นได้ (view) เท่านั้น ไม่เช่นนั้นไม่เปิดอะไร
        $deepLink = $this->deepLinkTicket($request);
        $modalTicket = $deepLink ?? $open;

        return response()
            ->view('tickets.landing', [
                'state' => TicketDesign::reporterState($open),
                'openTicket' => $open,
                'historyTotal' => (clone $ownTickets)->count(),
                'history' => (clone $ownTickets)->with('closingActivity')->latest()->orderByDesc('id')->limit(self::REPORTER_HISTORY_LIMIT)->get(),
                'modalTicket' => $modalTicket ? $this->loadReadonly($modalTicket) : null,
                'openModalOnLoad' => $deepLink !== null,
                'canCreate' => $open === null && $user->can('create', Ticket::class),
                ...($open === null && $user->can('create', Ticket::class) ? $this->submissionForm($request) : []),
            ])
            // สถานะต้องมาจาก server ทุกครั้ง — กลับมาด้วยปุ่ม Back ต้องไม่เห็นการ์ดเก่าจาก cache
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function listData(Request $request, TicketQueryService $tickets, User $user): array
    {
        $filters = $tickets->filters($user, $request->query());

        return [
            'tickets' => $tickets->paginate($user, $filters),
            'filters' => $filters,
            'scopeOptions' => $tickets->scopeOptions($user),
            'locations' => TicketLocation::query()->ordered()->get(['id', 'name']),
            'renderedAt' => now(),
        ];
    }

    private function deepLinkTicket(Request $request): ?Ticket
    {
        $id = $request->query('ticket');
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $ticket = Ticket::query()->find((int) $id);

        return $ticket && $request->user()->can('view', $ticket) ? $ticket : null;
    }

    /**
     * ข้อมูลของฟอร์มแจ้งปัญหา — ชุดเดียวกันทั้งหน้า /tickets/create และ modal บนหน้า /tickets
     *
     * @return array{requester: User, locations: Collection, submissionKey: string}
     */
    private function submissionForm(Request $request): array
    {
        return [
            'requester' => $request->user()->loadMissing('department'),
            'locations' => TicketLocation::query()->active()->ordered()->get(['id', 'name']),
            'submissionKey' => old('submission_key', (string) Str::uuid()),
        ];
    }

    private function loadDetail(Ticket $ticket): void
    {
        $ticket->load([
            'participants' => fn ($query) => $query->orderBy('joined_at'),
            'participants.user.department',
            'participants.user.ticketAgent',
            'activeParticipants',
            'activities.attachments',
            'closingActivity',
            'attachments' => fn ($query) => $query->whereNull('ticket_activity_id'),
        ]);
    }

    /** เฉพาะสิ่งที่ผู้แจ้ง/หัวหน้าแผนกเห็นได้ — ไม่โหลดผู้เข้าร่วม และรูปของบันทึกภายใน */
    private function loadReadonly(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'activities',
            'closingActivity',
            'attachments' => fn ($query) => $query->whereNull('ticket_activity_id'),
            // ผู้รับผิดชอบที่ผู้แจ้งเห็น (components/readonly-responsible) — ชื่อ แผนก และรูปโปรไฟล์เท่านั้น
            'activeParticipants' => fn ($query) => $query->orderBy('joined_at'),
            'activeParticipants.user.department',
        ]);
    }
}
