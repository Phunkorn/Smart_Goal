<?php

namespace App\Services;

use App\Exceptions\TicketStatusConflict;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Support\TicketDesign;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ทางเดียวที่เปลี่ยนสถานะของ Ticket
 *
 * ลำดับการตรวจ (ทั้งหมดอยู่ใต้ล็อกแถว Ticket):
 *   1. สถานะปัจจุบันต้องตรงกับ expectedStatus ที่หน้าจอของผู้ใช้เห็น — ไม่ตรงคือมีคนกดไปก่อน
 *      โยน TicketStatusConflict (409) แทนการทำซ้ำหรือเขียนทับ
 *   2. action ต้องทำได้จากสถานะนี้ตาม Ticket::TRANSITIONS
 *   3. ผู้ใช้ต้องผ่าน TicketPolicy กับแถวที่ล็อกไว้
 *
 * ปิดงานแนบ "รูปหลังแก้ไข" ได้ (ไม่บังคับ) — ผูกกับ activity ของการปิดงาน (ticket_activity_id) จึงเป็นข้อมูลภายใน
 * (MediaController ใช้ viewInternal) ผู้แจ้ง/หัวหน้าแผนกไม่เห็น · บันทึกในทรานแซกชันเดียวกับการเปลี่ยนสถานะ
 * ทรานแซกชันล้มเมื่อใด ไฟล์ที่เขียนไปแล้วถูกลบ (แบบเดียวกับ TicketNoteService)
 *
 * first_responded_at ถูกตั้งครั้งเดียวตอนรับงานครั้งแรก และไม่ถูกแตะเมื่อเปิดงานใหม่
 * เวลาตอบรับใน KPI จึงไม่เปลี่ยนย้อนหลัง ทุกการเปลี่ยนเขียน ticket_activities หนึ่งแถว
 */
class TicketWorkflowService
{
    /** action ที่ต้องมีข้อความประกอบ => ข้อความเมื่อไม่ได้กรอก */
    private const NOTE_REQUIRED = [
        Ticket::ACTION_FOLLOW_UP => 'กรุณาระบุเหตุผลที่ต้องติดตาม',
        Ticket::ACTION_CLOSE => 'กรุณาบันทึกผลการแก้ไขก่อนปิดงาน',
        Ticket::ACTION_CANCEL => 'กรุณาระบุเหตุผลที่ยกเลิกงาน',
        Ticket::ACTION_REOPEN => 'กรุณาระบุเหตุผลที่เปิดงานใหม่',
    ];

    public function __construct(
        private readonly TicketParticipantService $participants,
        private readonly TicketNotificationService $notifier,
        private readonly TicketPhotoStore $photoStore,
    ) {}

    /**
     * @param  array{note?: string|null, follow_up_date?: string|null, photos?: array<int, UploadedFile>}  $payload
     */
    public function transition(Ticket $ticket, string $action, User $actor, string $expectedStatus, array $payload = []): Ticket
    {
        if (! array_key_exists($action, Ticket::TRANSITIONS)) {
            throw ValidationException::withMessages(['action' => 'ไม่รู้จักการดำเนินการนี้']);
        }

        $note = trim((string) ($payload['note'] ?? ''));
        if ($note === '' && isset(self::NOTE_REQUIRED[$action])) {
            throw ValidationException::withMessages(['note' => self::NOTE_REQUIRED[$action]]);
        }

        $followUpDate = $action === Ticket::ACTION_FOLLOW_UP
            ? $this->parseDate($payload['follow_up_date'] ?? null)
            : null;

        $photos = array_values($payload['photos'] ?? []);
        if ($photos !== [] && $action !== Ticket::ACTION_CLOSE) {
            throw ValidationException::withMessages(['photos' => 'แนบรูปได้เฉพาะตอนปิดงาน']);
        }
        $photos = $this->photoStore->validated($photos);
        $storedPaths = [];

        try {
            [$updated, $activity] = $this->transact($ticket, $action, $actor, $expectedStatus, $note, $followUpDate, $photos, $storedPaths);
        } catch (Throwable $exception) {
            $this->photoStore->discard($storedPaths);

            throw $exception;
        }

        // หลัง commit เท่านั้น — ความผิดพลาดของการแจ้งเตือนไม่ย้อนกลับการเปลี่ยนสถานะ
        $this->notifier->statusChanged($updated, $activity, $actor);

        return $updated;
    }

    /**
     * ส่วนที่อยู่ใต้ล็อกแถว Ticket — ตรวจ เปลี่ยนสถานะ เขียน activity และเก็บรูปของการปิดงาน
     *
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, string>  $storedPaths  path ของไฟล์ที่เขียนแล้ว (ให้ผู้เรียกลบเมื่อทรานแซกชันล้ม)
     * @return array{0: Ticket, 1: TicketActivity}
     */
    private function transact(Ticket $ticket, string $action, User $actor, string $expectedStatus, string $note, ?CarbonImmutable $followUpDate, array $photos, array &$storedPaths): array
    {
        return DB::transaction(function () use ($ticket, $action, $actor, $expectedStatus, $note, $followUpDate, $photos, &$storedPaths): array {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($locked->status !== $expectedStatus) {
                throw new TicketStatusConflict($locked->status);
            }

            if (! Ticket::allowsTransition($locked->status, $action)) {
                throw ValidationException::withMessages([
                    'action' => 'ไม่สามารถ'.TicketDesign::actionLabel($action)
                        .'จากสถานะ "'.TicketDesign::status($locked->status)['label'].'" ได้',
                ]);
            }

            Gate::forUser($actor)->authorize(TicketPolicy::abilityFor($action), $locked);

            if ($action === Ticket::ACTION_START) {
                // ผู้กดรับงานเป็นผู้เข้าร่วมเสมอ — การเข้าร่วมยังเป็นแถวแยกใน timeline
                $this->participants->joinLocked($locked, $actor);
            }

            if ($action === Ticket::ACTION_REOPEN) {
                $this->ensureRequesterHasNoOtherOpenTicket($locked);
            }

            $fromStatus = $locked->status;
            try {
                $locked->forceFill([
                    'status' => Ticket::TRANSITIONS[$action]['to'],
                    ...$this->changesFor($locked, $action, $actor, $followUpDate),
                ])->save();
            } catch (UniqueConstraintViolationException $exception) {
                // ด่านสุดท้าย (unique index tickets_open_line_user_unique / tickets_open_requester_unique)
                // — อีกคำขอเปิดใบอื่นของผู้แจ้งเดียวกันไปก่อน
                $this->ensureRequesterHasNoOtherOpenTicket($locked);

                throw $exception;
            }

            $activity = TicketActivity::query()->forceCreate([
                'ticket_id' => $locked->id,
                'user_id' => $actor->id,
                'user_name' => $actor->name,
                'type' => TicketActivity::TYPE_STATUS_CHANGED,
                'from_status' => $fromStatus,
                'to_status' => $locked->status,
                'body' => $note === '' ? null : $note,
                'data' => array_filter([
                    'action' => $action,
                    'follow_up_date' => $followUpDate?->toDateString(),
                ]),
            ]);

            if ($photos !== []) {
                $storedPaths = $this->photoStore->store($locked, $photos, $actor, $activity->id);
            }

            return [$locked, $activity];
        });
    }

    /**
     * ผู้แจ้งหนึ่งรายมี Ticket ที่ยังไม่ปิดได้ใบเดียว — เปิดงานใหม่ใบเก่าไม่ได้ถ้าผู้แจ้งคนเดิมมีใบอื่นที่ยังเปิดอยู่
     *   - ฟอร์มสาธารณะ: ตัวตนคือบัญชี LINE (line_user_id)
     *   - ผู้แจ้งภายใน: ตัวตนคือบัญชี Smart Goals (requester_id ของ Ticket source = internal)
     * Ticket สาธารณะที่ไม่ผูก LINE และ Ticket ภายในที่ผู้แจ้งถูกลบถาวร (requester_id = null) ไม่เข้ากติกานี้
     */
    private function ensureRequesterHasNoOtherOpenTicket(Ticket $ticket): void
    {
        [$column, $value, $who] = match (true) {
            $ticket->line_user_id !== null => ['line_user_id', $ticket->line_user_id, 'บัญชี LINE เดียวกัน'],
            $ticket->source === Ticket::SOURCE_INTERNAL && $ticket->requester_id !== null => ['requester_id', $ticket->requester_id, 'บัญชีผู้ใช้เดียวกัน'],
            default => [null, null, null],
        };

        if ($column === null) {
            return;
        }

        $open = Ticket::query()
            ->where($column, $value)
            ->when($column === 'requester_id', fn ($query) => $query->where('source', Ticket::SOURCE_INTERNAL))
            ->where('status', '!=', Ticket::STATUS_CLOSED)
            ->whereKeyNot($ticket->getKey())
            ->first(['id', 'ticket_number', 'status']);

        if ($open) {
            throw ValidationException::withMessages([
                'status' => 'เปิดงานใหม่ไม่ได้: ผู้แจ้งรายนี้ ('.$who.') มี Ticket '.$open->ticket_number
                    .' (สถานะ "'.TicketDesign::status($open->status)['label'].'") ที่ยังไม่ปิด — ให้ดำเนินการต่อในใบนั้น',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function changesFor(Ticket $ticket, string $action, User $actor, ?CarbonImmutable $followUpDate): array
    {
        return match ($action) {
            Ticket::ACTION_START => [
                'first_responded_at' => $ticket->first_responded_at ?? now(),
            ],
            Ticket::ACTION_FOLLOW_UP => [
                'follow_up_date' => $followUpDate?->toDateString(),
            ],
            Ticket::ACTION_RESUME => [
                'follow_up_date' => null,
            ],
            // closed_by_ref_id คือตัวตนทางประวัติของผู้ปิด (ไม่มี FK — migration 2026_10_23_000008)
            // ตั้ง/ล้างคู่กับ closed_by ที่นี่ที่เดียว ไม่อนุมานจากการเปลี่ยนของ closed_by เพราะเมื่อบัญชี
            // ผู้ปิดถูกลบถาวร closed_by เป็น null อยู่แล้ว การเปิดงานใหม่จึงไม่ถือว่า "เปลี่ยน" และ ref จะค้าง
            // ยกเลิกงานปิด Ticket เหมือนปิดงาน (เวลาปิด ผู้ปิด) — ต่างกันที่ data.action ของ activity
            Ticket::ACTION_CLOSE, Ticket::ACTION_CANCEL => [
                'follow_up_date' => null,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'closed_by_ref_id' => $actor->id,
            ],
            Ticket::ACTION_REOPEN => [
                'closed_at' => null,
                'closed_by' => null,
                'closed_by_ref_id' => null,
                'reopen_count' => $ticket->reopen_count + 1,
            ],
        };
    }

    /** วันติดตามถัดไปเป็นวันที่ตามปฏิทินธุรกิจ (ไม่มีเวลา) — ไม่บังคับ */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value);

        if ($date === false || $date->format('Y-m-d') !== (string) $value) {
            throw ValidationException::withMessages(['follow_up_date' => 'วันติดตามถัดไปไม่ถูกต้อง']);
        }

        return $date;
    }
}
