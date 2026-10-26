<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Support\TicketAgentAccess;
use App\Support\TicketDesign;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * การแจ้งเตือนของ Smart Ticket — ส่งผ่าน NotificationService เดิมทั้งหมด
 *
 * ช่องทาง
 * ---------------------------------------------------------------
 * - ในระบบ: system_notifications หมวด "ticket" (กระดิ่ง, ศูนย์การแจ้งเตือน, realtime-sync)
 * - Telegram: NotificationService::create() ส่งต่อให้ TelegramOutbox เองเมื่อผู้รับผูกบัญชีไว้
 * - เสียง: components/ticket-alert.js ฟัง event ของ realtime-sync เฉพาะชนิด ticket_created
 *
 * กติกา
 * ---------------------------------------------------------------
 * - ผู้รับทุกคนต้องผ่าน TicketPolicy::view() ของ Ticket ใบนั้น (ข้อความมีรายละเอียดของ Ticket)
 * - notifyDetached() ตัด viewer, บัญชีปิดใช้งาน และผู้กระทำเองออกให้อีกชั้น
 * - dedupe_key ต่อเหตุการณ์ → แจ้งซ้ำไม่ได้ และ Telegram ไม่ถูกส่งซ้ำ (ส่งเฉพาะแถวที่เพิ่งสร้าง)
 * - เรียกหลัง commit เท่านั้น และความผิดพลาดของการแจ้งเตือนถูกบันทึก log แต่ไม่ถูกโยนต่อ
 *   การสร้าง/อัปเดต Ticket จึงไม่ล้มเพราะการแจ้งเตือน
 * - ข้อความไม่มีช่องทางติดต่อหรือชื่อผู้แจ้งภายนอก ส่งเท่าที่ต้องใช้ตัดสินใจรับงาน
 */
class TicketNotificationService
{
    public const TYPE_CREATED = 'ticket_created';

    public const TYPE_STATUS_CHANGED = 'ticket_status_changed';

    public const TYPE_NOTE_ADDED = 'ticket_note_added';

    /** ความสัมพันธ์ที่ TicketPolicy::view() อ่าน (TicketAgentAccess::isAgent) — โหลดพร้อมกันทุกผู้รับ ไม่ใช่ทีละคน */
    private const ACCESS_RELATIONS = ['ticketAgent', 'department'];

    public function __construct(private readonly NotificationService $notifications) {}

    /** Ticket ใหม่ → agent ที่เปิดรับแจ้งเตือน (ยังไม่มี agent เลย → admin) */
    public function ticketCreated(Ticket $ticket, ?User $actor = null): void
    {
        $this->safely('created', $ticket, function () use ($ticket, $actor): void {
            $recipients = TicketAgentAccess::agentsQuery()->exists()
                ? TicketAgentAccess::alertRecipientsQuery()->with(self::ACCESS_RELATIONS)->get()
                : $this->activeAdmins();

            $this->send($ticket, $recipients, self::TYPE_CREATED,
                'Ticket ใหม่ '.$ticket->ticket_number,
                $this->summaryLines($ticket),
                $actor,
                'ticket-created:'.$ticket->id);
        });
    }

    /** เปลี่ยนสถานะ → ผู้แจ้ง (ภายใน) และผู้เข้าร่วมปัจจุบัน */
    public function statusChanged(Ticket $ticket, TicketActivity $activity, User $actor): void
    {
        $this->safely('status', $ticket, function () use ($ticket, $activity, $actor): void {
            $status = TicketDesign::status($ticket->status)['label'];
            $action = TicketDesign::activity($activity)['title'];

            $this->send($ticket, $this->involved($ticket), self::TYPE_STATUS_CHANGED,
                $ticket->ticket_number.' · '.$action,
                $actor->name.' '.$action.' — สถานะปัจจุบัน: '.$status."\n".$ticket->summary,
                $actor,
                'ticket-status:'.$activity->id);
        });
    }

    /**
     * บันทึกการดำเนินงานใหม่ → ผู้เข้าร่วมปัจจุบันเท่านั้น
     * บันทึกเป็นข้อมูลภายในของผู้รับงาน (TicketPolicy::viewInternal) ข้อความแจ้งเตือนมีเนื้อหาบันทึก จึงไม่ส่งถึงผู้แจ้ง
     * ผู้แจ้งยังได้แจ้งเตือนเมื่อสถานะเปลี่ยน (statusChanged) ตามเดิม
     */
    public function noteAdded(Ticket $ticket, TicketActivity $note, User $author): void
    {
        $this->safely('note', $ticket, function () use ($ticket, $note, $author): void {
            $this->send($ticket, $this->workers($ticket), self::TYPE_NOTE_ADDED,
                $ticket->ticket_number.' · บันทึกการดำเนินงานใหม่',
                $author->name.': '.Str::limit((string) $note->body, 300, '…'),
                $author,
                'ticket-note:'.$note->id);
        });
    }

    /** @param  Collection<int, User>  $recipients */
    private function send(Ticket $ticket, Collection $recipients, string $type, string $title, string $message, ?User $actor, string $dedupePrefix): void
    {
        $allowed = $recipients
            ->filter(fn (User $user) => Gate::forUser($user)->allows('view', $ticket))
            ->pluck('id');

        if ($allowed->isEmpty()) {
            return;
        }

        $this->notifications->notifyDetached(
            $allowed,
            $type,
            Str::limit($title, 200, ''),
            Str::limit($message, 1000, ''),
            $actor,
            ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
            [],
            $dedupePrefix,
        );
    }

    /** @return Collection<int, User> */
    private function involved(Ticket $ticket): Collection
    {
        $ids = $ticket->activeParticipants()->pluck('user_id')
            ->push($ticket->requester_id)
            ->filter()
            ->unique();

        return User::query()->whereIn('id', $ids)->with(self::ACCESS_RELATIONS)->get();
    }

    /** @return Collection<int, User> ผู้เข้าร่วมปัจจุบันที่ยังเห็นข้อมูลภายในของ Ticket ได้ */
    private function workers(Ticket $ticket): Collection
    {
        return User::query()
            ->whereIn('id', $ticket->activeParticipants()->pluck('user_id')->filter())
            ->with(self::ACCESS_RELATIONS)
            ->get()
            ->filter(fn (User $user) => Gate::forUser($user)->allows('viewInternal', $ticket))
            ->values();
    }

    /** @return Collection<int, User> */
    private function activeAdmins(): Collection
    {
        return User::query()->where('role', 'admin')->where('is_active', true)->with(self::ACCESS_RELATIONS)->get();
    }

    /** ข้อความสั้นสำหรับ Ticket ใหม่: ปัญหาโดยย่อ · สาขา · เวลาแจ้ง (ไม่มีช่องทางติดต่อ) */
    private function summaryLines(Ticket $ticket): string
    {
        return implode("\n", [
            $ticket->summary,
            'สาขา/สถานที่: '.$ticket->location_name,
            'แจ้งเมื่อ '.TicketDesign::when($ticket->created_at),
        ]);
    }

    /**
     * การแจ้งเตือนเป็นผลข้างเคียงหลังบันทึกสำเร็จแล้ว — ห้ามทำให้ผู้ใช้เห็นว่าการทำงานล้ม
     * บันทึก log พร้อมเลข Ticket เพื่อให้ตามแก้ได้ ไม่ใช่กลืนเงียบ
     */
    private function safely(string $event, Ticket $ticket, Closure $send): void
    {
        try {
            $send();
        } catch (Throwable $exception) {
            Log::error('Smart Ticket notification failed', [
                'event' => $event,
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'exception' => $exception,
            ]);
        }
    }
}
