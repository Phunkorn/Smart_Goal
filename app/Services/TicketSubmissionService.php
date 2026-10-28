<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketLocation;
use App\Models\User;
use App\Support\PublicTicketDepartments;
use App\Support\TicketDesign;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * สร้าง Ticket ทั้งจากผู้ใช้ภายในและจากฟอร์มสาธารณะ
 *
 * ที่นี่คือที่เดียวที่ตั้ง snapshot ของผู้แจ้ง แผนก และสาขา และออกเลข Ticket
 * ข้อมูลผู้แจ้งภายในอ่านจากบัญชีที่ login เสมอ ไม่รับจาก input — กันการส่ง
 * department_id ของคนอื่นเข้ามาปลอมแปลง
 *
 * ทั้งหมดหรือไม่มีเลย
 * ---------------------------------------------------------------
 * Ticket, activity, แถวรูป และไฟล์รูป ถูกสร้างในทรานแซกชันเดียว ถ้าขั้นใดล้ม
 * (เก็บไฟล์ไม่ได้ บันทึกแถวไม่ได้ หรือ commit ไม่ผ่าน) ทรานแซกชันย้อนกลับ และไฟล์ที่
 * เก็บไปแล้วถูกลบทิ้ง (TicketPhotoStore) จึงไม่มี Ticket ที่ข้อมูลไม่ครบ และไม่มีไฟล์ค้าง
 *
 * กดส่งซ้ำ
 * ---------------------------------------------------------------
 * ฟอร์มส่ง submission_key (UUID ต่อการเปิดฟอร์มหนึ่งครั้ง) มาด้วย คำขอแรกจองคีย์ใน cache
 * แบบ atomic คำขอซ้ำด้วยคีย์เดิมได้ Ticket ใบเดิมกลับไป ไม่สร้างใบใหม่
 *
 * หนึ่งบัญชี LINE หนึ่งเคสที่ยังไม่ปิด (ฟอร์มสาธารณะ)
 * ---------------------------------------------------------------
 * ผู้แจ้งที่ยืนยันตัวด้วย LINE Login (LineLogin) เปิด Ticket ใหม่ได้เมื่อ Ticket เดิมของบัญชีนั้นถูกปิดแล้วเท่านั้น
 * ตรวจภายใต้ cache lock ต่อบัญชี กันสองแท็บส่งพร้อมกันแล้วได้สองใบ
 */
class TicketSubmissionService
{
    public const NAME_MAX = 150;

    /** อายุของการจอง submission_key — นานพอครอบคลุมการกดซ้ำและการ refresh หลังส่ง */
    private const SUBMISSION_KEY_MINUTES = 30;

    /** เบอร์โทรของฟอร์มสาธารณะ: ตัวเลข 10 หลัก ขึ้นต้นด้วย 0 (StorePublicTicketRequest ใช้ชุดเดียวกัน) */
    public const PHONE_PATTERN = '/^0[0-9]{9}$/';

    /**
     * รหัส AnyDesk / โปรแกรมรีโมท: เลขประจำเครื่องเท่านั้น (ตัวเลข เว้นวรรค ขีด 6–20 ตัว) — ไม่รับตัวอักษร
     * จึงพิมพ์รหัสผ่านลงช่องนี้ไม่ได้ ระบบไม่เก็บรหัสผ่านของการเชื่อมต่อระยะไกล
     */
    public const REMOTE_ACCESS_PATTERN = '/^[0-9][0-9 \-]{5,19}$/';

    private const OPEN_TICKET_LOCK_SECONDS = 10;

    public const LINE_REQUIRED = 'กรุณาเข้าสู่ระบบด้วย LINE ก่อนส่งแจ้งปัญหา';

    public const SERVICE_UNAVAILABLE = 'ระบบแจ้งปัญหาออนไลน์ปิดให้บริการชั่วคราว กรุณาติดต่อทีม IT ทางโทรศัพท์';

    public function __construct(
        private readonly TicketPhotoStore $photoStore,
        private readonly TicketNotificationService $notifier,
        private readonly LineLogin $lineLogin,
    ) {}

    /**
     * @param  array{description: string, contact?: string|null, remote_access?: string|null, location_id?: int|string|null, location_name?: string|null, submission_key?: string|null}  $input
     * @param  array<int, UploadedFile>  $photos
     */
    public function submitInternal(User $requester, array $input, array $photos = []): Ticket
    {
        Gate::forUser($requester)->authorize('create', Ticket::class);

        $requester->loadMissing('department');
        $contact = $this->contactValue($input['contact'] ?? null);
        $remote = $this->remoteAccessValue($input['remote_access'] ?? null);
        $findOpen = fn (): ?Ticket => $this->openTicketForRequester($requester->id);

        // หนึ่งผู้แจ้ง หนึ่ง Ticket ที่ยังไม่ปิด — ตรวจภายใต้ lock ต่อผู้แจ้ง และ unique index tickets_open_requester_unique
        // คีย์ submission เดิม (กดส่งซ้ำ/refresh) ยังได้ใบเดิมก่อนการตรวจนี้ จึงไม่ถูกปฏิเสธผิด
        return $this->oneOpenTicket(
            'ticket-requester:'.$requester->id,
            $findOpen,
            'กำลังบันทึกการแจ้งปัญหาของคุณอยู่ กรุณารอสักครู่แล้วลองอีกครั้ง',
            fn () => $this->once($input['submission_key'] ?? null, 'user:'.$requester->id, function () use ($findOpen, $requester, $contact, $remote, $input, $photos): Ticket {
                if ($open = $findOpen()) {
                    throw ValidationException::withMessages(['form' => self::openTicketMessage($open)]);
                }

                return $this->open([
                    'source' => Ticket::SOURCE_INTERNAL,
                    'requester_id' => $requester->id,
                    'requester_name' => $requester->name,
                    // แผนกของผู้ใช้ภายในอ่านจากบัญชีที่ login เสมอ ไม่รับจากฟอร์ม
                    'requester_department_id' => $requester->department?->id,
                    'requester_department_name' => $requester->department?->department_name,
                    'contact' => $contact,
                    'remote_access' => $remote,
                    'submitter_ip' => null,
                ], $input, $photos, $requester);
            }),
        );
    }

    /**
     * @param  array{requester_name: string, requester_department: string, description: string, contact?: string|null, remote_access?: string|null, location_id?: int|string|null, location_name?: string|null, submission_key?: string|null}  $input
     * @param  array<int, UploadedFile>  $photos
     * @param  array{user_id: string, name: string}|null  $line  ตัวตนจาก LINE Login ใน session ของผู้แจ้ง (ไม่ใช่จากฟอร์ม)
     */
    public function submitPublic(array $input, ?string $submitterIp, array $photos = [], ?array $line = null): Ticket
    {
        $name = $this->requiredText($input['requester_name'] ?? null, 'requester_name', 'กรุณาระบุชื่อผู้แจ้ง');
        $department = $input['requester_department'] ?? null;
        $contact = $this->contactValue($input['contact'] ?? null);
        $remote = $this->remoteAccessValue($input['remote_access'] ?? null);
        $lineUserId = $line['user_id'] ?? null;

        // ชั้นสุดท้ายหลัง Form Request — แผนกรับได้เฉพาะค่าในรายการปิด ค่าอื่นคือการแก้ HTML
        if (! PublicTicketDepartments::allows($department)) {
            throw ValidationException::withMessages(['requester_department' => 'กรุณาเลือกแผนก']);
        }

        // ชั้นสุดท้ายหลัง Form Request — ผู้เรียกทุกทางต้องมีตัวตน LINE ที่ยืนยันแล้วเมื่อระบบบังคับ LINE
        if ($this->lineLogin->unavailable()) {
            throw ValidationException::withMessages(['form' => self::SERVICE_UNAVAILABLE]);
        }
        if ($lineUserId === null && $this->lineLogin->required()) {
            throw ValidationException::withMessages(['form' => self::LINE_REQUIRED]);
        }

        $submit = fn () => $this->once($input['submission_key'] ?? null, 'ip:'.$submitterIp, function () use ($name, $department, $contact, $remote, $line, $lineUserId, $submitterIp, $input, $photos): Ticket {
            if ($lineUserId !== null && ($open = $this->openTicketForLine($lineUserId))) {
                throw ValidationException::withMessages(['form' => self::openTicketMessage($open)]);
            }

            return $this->open([
                'source' => Ticket::SOURCE_PUBLIC,
                'requester_id' => null,
                'requester_name' => $name,
                // แผนกที่ผู้แจ้งเลือกเองเก็บเป็น "ชื่อ" เท่านั้น — requester_department_id ต้องเป็น null ตลอด
                // เพราะ TicketPolicy และ TicketQueryService ใช้คอลัมน์นั้นให้สิทธิ์หัวหน้าแผนกเห็น Ticket ของแผนกตัวเอง
                'requester_department_id' => null,
                'requester_department_name' => $department,
                'contact' => $contact,
                'remote_access' => $remote,
                'line_user_id' => $lineUserId,
                'line_display_name' => $lineUserId === null ? null : (($line['name'] ?? '') === '' ? null : $line['name']),
                'submitter_ip' => $submitterIp,
            ], $input, $photos, null);
        });

        if ($lineUserId === null) {
            return $submit();
        }

        return $this->oneOpenTicket(
            'ticket-line:'.$lineUserId,
            fn (): ?Ticket => $this->openTicketForLine($lineUserId),
            'กำลังบันทึกการแจ้งปัญหาของบัญชี LINE นี้อยู่ กรุณารอสักครู่แล้วลองอีกครั้ง',
            $submit,
        );
    }

    /**
     * เบอร์โทรจากฟอร์มของบัญชีที่ login สำหรับเติมค่าตั้งต้น — ตัดอักขระที่ไม่ใช่ตัวเลขแล้ว
     * คืนค่าเฉพาะเมื่อครบ 10 หลักตามรูปแบบจริง เบอร์ในโปรไฟล์ที่เป็นเบอร์บ้านหรือเบอร์ต่อ
     * จึงไม่ถูกเติมลงช่องแทนผู้แจ้ง (ให้กรอกเอง) — ใช้ที่ TicketController เท่านั้น
     */
    public static function prefillPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return preg_match(self::PHONE_PATTERN, (string) $digits) ? $digits : null;
    }

    /** ช่องทางติดต่อของทั้งสองช่องทาง — ความบังคับอยู่ที่ชั้น Form Request (TicketSubmissionRequest) */
    private function contactValue(mixed $value): ?string
    {
        $contact = trim((string) $value);

        return $contact === '' ? null : $contact;
    }

    /** รหัส AnyDesk/รีโมทของทั้งสองช่องทาง — ไม่บังคับ แต่ถ้ากรอกต้องเป็นเลขเครื่องเท่านั้น */
    private function remoteAccessValue(mixed $value): ?string
    {
        $remote = trim((string) $value);

        if ($remote === '') {
            return null;
        }

        if (! preg_match(self::REMOTE_ACCESS_PATTERN, $remote)) {
            throw ValidationException::withMessages(['remote_access' => 'กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน']);
        }

        return $remote;
    }

    /**
     * หนึ่งตัวตนมี Ticket ที่ยังไม่ปิดได้ใบเดียว — ใช้ทั้งบัญชี LINE (ฟอร์มสาธารณะ) และผู้แจ้งภายใน
     *
     * cache lock ลดการชนกันของสองแท็บ (รอได้ไม่เกิน 5 วินาที) ส่วนด่านที่รับประกันจริงคือ unique index
     * บนคอลัมน์คำนวณ (open_line_user_id / open_requester_id) — คำขอที่หลุด lock มาชน index แล้วได้ข้อความเดียวกัน
     *
     * @param  Closure(): ?Ticket  $findOpen
     * @param  Closure(): Ticket  $submit  ต้องตรวจ $findOpen เองภายใน (หลังคีย์ submission เดิม)
     */
    private function oneOpenTicket(string $lockKey, Closure $findOpen, string $busyMessage, Closure $submit): Ticket
    {
        try {
            return Cache::lock($lockKey, self::OPEN_TICKET_LOCK_SECONDS)->block(5, $submit);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['form' => $busyMessage]);
        } catch (UniqueConstraintViolationException $exception) {
            $open = $findOpen();
            if (! $open) {
                throw $exception;
            }

            throw ValidationException::withMessages(['form' => self::openTicketMessage($open)]);
        }
    }

    /** Ticket ภายในที่ยังไม่ปิดของผู้แจ้งคนนี้ (ใบล่าสุด) — ทุกสถานะยกเว้น "ปิดงาน" */
    public function openTicketForRequester(int $requesterId): ?Ticket
    {
        return Ticket::query()
            ->where('source', Ticket::SOURCE_INTERNAL)
            ->where('requester_id', $requesterId)
            ->where('status', '!=', Ticket::STATUS_CLOSED)
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    /** Ticket ที่ยังไม่ปิดของบัญชี LINE นี้ (ใบล่าสุด) — ทุกสถานะยกเว้น "ปิดงาน" */
    public function openTicketForLine(string $lineUserId): ?Ticket
    {
        return Ticket::query()
            ->where('line_user_id', $lineUserId)
            ->where('status', '!=', Ticket::STATUS_CLOSED)
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    public static function openTicketMessage(Ticket $open): string
    {
        return 'คุณมี Ticket '.$open->ticket_number.' (สถานะ "'.TicketDesign::status($open->status)['label']
            .'") ที่ยังไม่ปิด — แจ้งเรื่องใหม่ได้เมื่อเจ้าหน้าที่ปิดงานใบเดิมแล้ว';
    }

    /**
     * @param  array<string, mixed>  $requesterAttributes
     * @param  array<string, mixed>  $input
     * @param  array<int, UploadedFile>  $photos
     */
    private function open(array $requesterAttributes, array $input, array $photos, ?User $actor): Ticket
    {
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'กรุณาระบุรายละเอียดปัญหา']);
        }

        [$locationId, $locationName] = $this->resolveLocation($input);
        $photos = $this->photoStore->validated($photos);
        $storedPaths = [];

        try {
            $ticket = DB::transaction(function () use ($requesterAttributes, $description, $locationId, $locationName, $photos, $actor, &$storedPaths): Ticket {
                $ticket = new Ticket;
                $ticket->forceFill([
                    ...$requesterAttributes,
                    'description' => $description,
                    'status' => Ticket::STATUS_NEW,
                    'location_id' => $locationId,
                    'location_name' => $locationName,
                ])->save();

                // เลขคำนวณจาก id ที่เพิ่งได้ ภายในทรานแซกชันเดียวกัน จึงไม่ชนกันและไม่ต้องล็อกตัวนับ
                $ticket->forceFill(['ticket_number' => Ticket::formatNumber($ticket->id)])->save();

                TicketActivity::query()->forceCreate([
                    'ticket_id' => $ticket->id,
                    'user_id' => $actor?->id,
                    'user_name' => $ticket->requester_name,
                    'type' => TicketActivity::TYPE_CREATED,
                    'to_status' => Ticket::STATUS_NEW,
                ]);

                // รูปตอนแจ้งไม่ผูกกับ activity ใด (ticket_activity_id = null)
                $storedPaths = $this->photoStore->store($ticket, $photos, $actor);

                return $ticket;
            });
        } catch (Throwable $exception) {
            $this->photoStore->discard($storedPaths);

            throw $exception;
        }

        // หลัง commit เท่านั้น — ความผิดพลาดของการแจ้งเตือนไม่ย้อนกลับ Ticket ที่บันทึกแล้ว
        $this->notifier->ticketCreated($ticket, $actor);

        return $ticket;
    }

    /**
     * จอง submission_key ก่อนสร้าง — คำขอซ้ำได้ Ticket ใบเดิม คำขอที่มาระหว่างกำลังบันทึกได้ข้อความให้รอ
     * สร้างล้ม → ปล่อยคีย์ ให้กดส่งใหม่ได้
     *
     * @param  Closure(): Ticket  $create
     */
    private function once(?string $submissionKey, string $scope, Closure $create): Ticket
    {
        if ($submissionKey === null || $submissionKey === '') {
            return $create();
        }

        $cacheKey = self::submissionCacheKey($scope, $submissionKey);

        if (! Cache::add($cacheKey, 0, now()->addMinutes(self::SUBMISSION_KEY_MINUTES))) {
            $existing = Ticket::query()->find((int) Cache::get($cacheKey));

            if ($existing) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'form' => 'กำลังบันทึกการแจ้งปัญหานี้อยู่ กรุณารอสักครู่',
            ]);
        }

        try {
            $ticket = $create();
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);

            throw $exception;
        }

        Cache::put($cacheKey, $ticket->id, now()->addMinutes(self::SUBMISSION_KEY_MINUTES));

        return $ticket;
    }

    /** id ของ Ticket ที่คีย์ submission นี้สร้างไปแล้ว (null = ยังไม่เคยใช้) — Form Request ใช้แยก "กดส่งซ้ำ" ออกจาก "แจ้งเรื่องใหม่" */
    public function submissionFor(string $scope, mixed $submissionKey): ?int
    {
        if (! is_string($submissionKey) || $submissionKey === '') {
            return null;
        }

        $id = (int) Cache::get(self::submissionCacheKey($scope, $submissionKey));

        return $id > 0 ? $id : null;
    }

    private static function submissionCacheKey(string $scope, string $submissionKey): string
    {
        return 'ticket-submission:'.$scope.':'.$submissionKey;
    }

    /**
     * สาขาจากรายการที่ admin เปิดใช้งาน หรือชื่อที่ผู้แจ้งพิมพ์เองเมื่อเลือก "อื่น ๆ"
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int|null, 1: string}
     */
    private function resolveLocation(array $input): array
    {
        $locationId = $input['location_id'] ?? null;

        if ($locationId !== null && $locationId !== '') {
            $location = TicketLocation::query()->active()->find($locationId);

            if (! $location) {
                throw ValidationException::withMessages([
                    'location_id' => 'สาขาที่เลือกไม่มีอยู่หรือถูกปิดใช้งานแล้ว กรุณาเลือกใหม่',
                ]);
            }

            return [$location->id, $location->name];
        }

        return [null, $this->requiredText($input['location_name'] ?? null, 'location_name', 'กรุณาระบุสาขาหรือสถานที่')];
    }

    private function requiredText(mixed $value, string $field, string $message): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([$field => $message]);
        }

        if (mb_strlen($text) > self::NAME_MAX) {
            throw ValidationException::withMessages([$field => 'ข้อความยาวเกิน '.self::NAME_MAX.' ตัวอักษร']);
        }

        return $text;
    }
}
