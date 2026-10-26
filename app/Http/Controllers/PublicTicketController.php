<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tickets\StorePublicTicketRequest;
use App\Models\TicketLocation;
use App\Services\LineLogin;
use App\Services\TicketSubmissionService;
use App\Support\TicketSubmissionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ฟอร์มแจ้งปัญหาสาธารณะ /support — ไม่ต้องเข้าสู่ระบบ Smart Goal
 *
 * ไม่มีหน้าใดในกลุ่มนี้รับเลขหรือ id ของ Ticket จาก URL
 * หน้ายืนยันอ่านเลข Ticket ชื่อผู้แจ้ง และสาขา จาก session ของผู้ส่งเองเท่านั้น (ข้อมูลที่ผู้ส่งเพิ่งกรอกเอง) การเดา id จึงไม่ได้อะไร
 *
 * LINE Login (LineLogin) — เมื่อตั้งค่าช่องทางไว้ ผู้แจ้งต้องยืนยันตัวด้วย LINE ก่อนเห็นฟอร์ม
 * หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว: มีใบที่ยังไม่ปิด → หน้านี้แสดงสถานะใบนั้นแทนฟอร์ม
 * (TicketSubmissionService ตรวจซ้ำตอนบันทึก) ตัวตน LINE อยู่ใน session ของผู้แจ้งเท่านั้น ไม่รับจากฟอร์ม
 */
class PublicTicketController extends Controller
{
    /** สรุป Ticket ล่าสุดที่ session นี้ส่ง (number, requester, location) — ใช้แสดงหน้ายืนยัน รวมถึงหลัง refresh */
    private const SESSION_KEY = 'support.submitted_ticket';

    /** state และ nonce ของรอบ LINE Login ที่กำลังทำ */
    private const LINE_PENDING_KEY = 'support.line_pending';

    public function create(Request $request, LineLogin $line, TicketSubmissionService $tickets): View|Response
    {
        // ต้องใช้ LINE แต่ยังไม่ได้ตั้งค่าช่องทาง → ปิดให้บริการชั่วคราว (ไม่ถอยไปรับ Ticket แบบไม่ผูก LINE)
        if ($line->unavailable()) {
            return response()->view('tickets.public.unavailable', [], 503);
        }

        $identity = LineLogin::identityFrom($request->session());

        if ($line->required() && $identity === null) {
            return view('tickets.public.line-login');
        }

        $openTicket = $identity ? $tickets->openTicketForLine($identity['user_id']) : null;
        if ($openTicket) {
            return view('tickets.public.open-ticket', ['ticket' => $openTicket]);
        }

        return view('tickets.public.create', [
            'locations' => TicketLocation::query()->active()->ordered()->get(['id', 'name']),
            'submissionKey' => old('submission_key', (string) Str::uuid()),
            // ออกใหม่ทุกครั้งที่แสดงฟอร์ม ไม่ใช้ค่าเดิมจาก old() — เวลาเริ่มกรอกต้องเป็นของรอบนี้
            'formToken' => TicketSubmissionGuard::issueToken(),
            'lineName' => $identity['name'] ?? null,
        ]);
    }

    public function store(StorePublicTicketRequest $request, TicketSubmissionService $tickets): RedirectResponse
    {
        $ticket = $tickets->submitPublic($request->validated(), $request->ip(), $request->photos(), $request->lineIdentity());

        $request->session()->put(self::SESSION_KEY, [
            'number' => $ticket->ticket_number,
            'requester' => $ticket->requester_name,
            'location' => $ticket->location_name,
        ]);

        return redirect()->route('support.submitted');
    }

    public function submitted(Request $request): View|RedirectResponse
    {
        $submitted = $request->session()->get(self::SESSION_KEY);

        if (! is_array($submitted) || ! is_string($submitted['number'] ?? null) || $submitted['number'] === '') {
            return redirect()->route('support.create');
        }

        return view('tickets.public.submitted', [
            'ticketNumber' => $submitted['number'],
            'requesterName' => (string) ($submitted['requester'] ?? ''),
            'locationName' => (string) ($submitted['location'] ?? ''),
        ]);
    }

    /** เริ่ม LINE Login — state กัน CSRF ของ callback และ nonce ผูก ID token กับรอบนี้ */
    public function lineRedirect(Request $request, LineLogin $line): RedirectResponse
    {
        abort_unless($line->enabled(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put(self::LINE_PENDING_KEY, ['state' => $state, 'nonce' => $nonce]);

        return redirect()->away($line->authorizeUrl($state, $nonce, route('support.line.callback')));
    }

    public function lineCallback(Request $request, LineLogin $line): RedirectResponse
    {
        abort_unless($line->enabled(), 404);

        $pending = $request->session()->pull(self::LINE_PENDING_KEY);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $stateMatches = is_array($pending) && is_string($pending['state'] ?? null) && $state !== '' && hash_equals($pending['state'], $state);
        $identity = $stateMatches && $code !== ''
            ? $line->identify($code, route('support.line.callback'), (string) $pending['nonce'])
            : null;

        if ($identity === null) {
            return redirect()->route('support.create')
                ->withErrors(['line' => $request->query('error') === 'access_denied'
                    ? 'ยกเลิกการเข้าสู่ระบบด้วย LINE แล้ว — ต้องเข้าสู่ระบบด้วย LINE ก่อนจึงแจ้งปัญหาได้'
                    : 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }

        // ตัวตนเปลี่ยน → ออก session ID ใหม่ (กัน session fixation)
        $request->session()->regenerate();
        $request->session()->put(LineLogin::SESSION_KEY, $identity);

        return redirect()->route('support.create');
    }
}
