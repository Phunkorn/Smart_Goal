<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * บันทึกการดำเนินงานของ Ticket (ข้อความ + รูปไม่บังคับ) — เป็นหนึ่งแถวใน timeline
 *
 * ตรวจสิทธิ์กับแถว Ticket ที่ล็อกไว้ (TicketPolicy::addNote) ข้อความและรูปบันทึกพร้อมกัน
 * ในทรานแซกชันเดียว ถ้าล้มจะไม่มีบันทึกที่รูปหาย และไม่มีรูปค้างที่ไม่มีบันทึก
 */
class TicketNoteService
{
    public function __construct(
        private readonly TicketPhotoStore $photoStore,
        private readonly TicketNotificationService $notifier,
    ) {}

    /** @param  array<int, UploadedFile>  $photos */
    public function add(Ticket $ticket, User $author, string $body, array $photos = []): TicketActivity
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'กรุณาพิมพ์บันทึกการดำเนินงาน']);
        }

        $photos = $this->photoStore->validated($photos);
        $storedPaths = [];

        try {
            [$locked, $note] = DB::transaction(function () use ($ticket, $author, $body, $photos, &$storedPaths): array {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
                Gate::forUser($author)->authorize('addNote', $locked);

                $note = TicketActivity::query()->forceCreate([
                    'ticket_id' => $locked->id,
                    'user_id' => $author->id,
                    'user_name' => $author->name,
                    'type' => TicketActivity::TYPE_NOTE,
                    'body' => $body,
                ]);

                $storedPaths = $this->photoStore->store($locked, $photos, $author, $note->id);

                return [$locked, $note];
            });
        } catch (Throwable $exception) {
            $this->photoStore->discard($storedPaths);

            throw $exception;
        }

        // หลัง commit เท่านั้น — ความผิดพลาดของการแจ้งเตือนไม่ย้อนกลับบันทึกที่บันทึกแล้ว
        $this->notifier->noteAdded($locked, $note, $author);

        return $note;
    }
}
