<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketParticipant;
use App\Models\User;
use App\Support\TicketAgentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * การเข้าร่วม การออก และผู้ประสานงานของ Ticket
 *
 * แยกจากการเปลี่ยนสถานะโดยตั้งใจ — การเข้าร่วมไม่เปลี่ยนสถานะ และไม่ล็อก Ticket
 * จากเจ้าหน้าที่คนอื่น ทุกการกระทำล็อกแถว Ticket ก่อน แล้วตรวจสิทธิ์กับแถวที่ล็อกไว้
 * จึงตัดสินจากสถานะล่าสุดเสมอ แม้มีคนกดพร้อมกัน
 */
class TicketParticipantService
{
    /** @return bool true เมื่อเพิ่งเข้าร่วม, false เมื่อเป็นผู้เข้าร่วมอยู่แล้ว */
    public function join(Ticket $ticket, User $actor): bool
    {
        return DB::transaction(function () use ($ticket, $actor): bool {
            $locked = $this->lock($ticket);
            Gate::forUser($actor)->authorize('join', $locked);

            return $this->joinLocked($locked, $actor);
        });
    }

    /**
     * เพิ่มผู้เข้าร่วมบน Ticket ที่ผู้เรียกล็อกไว้แล้ว — ใช้ร่วมกับ "รับงาน" ใน TicketWorkflowService
     *
     * unique (ticket_id, user_id) คือด่านสุดท้ายกันแถวซ้ำ insertOrIgnore จึงไม่โยน
     * error เมื่อมีอีกคำขอเพิ่มแถวเดียวกันไปก่อน
     */
    public function joinLocked(Ticket $locked, User $user): bool
    {
        $now = now();
        $existing = TicketParticipant::query()
            ->where('ticket_id', $locked->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing?->isActive()) {
            return false;
        }

        if ($existing) {
            $existing->forceFill([
                'user_name' => $user->name,
                'joined_at' => $now,
                'left_at' => null,
            ])->save();
        } else {
            $inserted = TicketParticipant::query()->insertOrIgnore([
                'ticket_id' => $locked->id,
                'user_id' => $user->id,
                // insertOrIgnore ข้าม model event — ตั้งตัวตนทางประวัติเองเหมือน TicketParticipant::booted()
                'user_ref_id' => $user->id,
                'user_name' => $user->name,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false;
            }
        }

        $this->record($locked, $user, TicketActivity::TYPE_JOINED);

        return true;
    }

    public function leave(Ticket $ticket, User $actor): void
    {
        DB::transaction(function () use ($ticket, $actor): void {
            $locked = $this->lock($ticket);
            Gate::forUser($actor)->authorize('leave', $locked);

            TicketParticipant::query()
                ->where('ticket_id', $locked->id)
                ->where('user_id', $actor->id)
                ->whereNull('left_at')
                ->update(['left_at' => now(), 'updated_at' => now()]);

            // ผู้ประสานงานต้องเป็นผู้เข้าร่วมปัจจุบันเสมอ ออกไปแล้วจึงล้างตำแหน่งนี้ด้วย
            $wasCoordinator = (int) $locked->coordinator_id === (int) $actor->id;
            if ($wasCoordinator) {
                $locked->forceFill(['coordinator_id' => null])->save();
            }

            $this->record($locked, $actor, TicketActivity::TYPE_LEFT, data: $wasCoordinator ? ['coordinator_cleared' => true] : null);
        });
    }

    /** ตั้งหรือล้าง (null) ผู้ประสานงานหลัก — ไม่บังคับต้องมี */
    public function setCoordinator(Ticket $ticket, User $actor, ?User $coordinator): bool
    {
        return DB::transaction(function () use ($ticket, $actor, $coordinator): bool {
            $locked = $this->lock($ticket);
            Gate::forUser($actor)->authorize('setCoordinator', $locked);

            // ผู้ประสานงานต้องยังเป็น agent ที่มีสิทธิ์อยู่ และกำลังเข้าร่วม Ticket นี้
            if ($coordinator && (! TicketAgentAccess::isAgent($coordinator) || ! $locked->hasActiveParticipant($coordinator))) {
                throw ValidationException::withMessages([
                    'coordinator_id' => 'ผู้ประสานงานต้องเป็นเจ้าหน้าที่ที่มีสิทธิ์และกำลังเข้าร่วม Ticket นี้อยู่',
                ]);
            }

            if ((int) $locked->coordinator_id === (int) $coordinator?->id) {
                return false;
            }

            $locked->forceFill(['coordinator_id' => $coordinator?->id])->save();

            $this->record($locked, $actor, TicketActivity::TYPE_COORDINATOR_SET, data: [
                'coordinator_id' => $coordinator?->id,
                'coordinator_name' => $coordinator?->name,
            ]);

            return true;
        });
    }

    private function lock(Ticket $ticket): Ticket
    {
        return Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    /** @param  array<string, mixed>|null  $data */
    private function record(Ticket $ticket, User $actor, string $type, ?array $data = null): void
    {
        TicketActivity::query()->forceCreate([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'type' => $type,
            'data' => $data,
        ]);
    }
}
