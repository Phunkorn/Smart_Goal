<?php

namespace Tests\Feature\Tickets;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAgent;
use App\Models\TicketLocation;
use App\Models\User;
use App\Services\TicketSubmissionService;
use App\Services\TicketWorkflowService;

/**
 * ตัวช่วยสร้างข้อมูลของ Smart Ticket ในเทสต์
 *
 * โปรเจกต์นี้มี factory เฉพาะ User — Ticket ถูกสร้างผ่าน TicketSubmissionService จริง
 * เทสต์จึงได้ snapshot และเลข Ticket แบบเดียวกับระบบจริง ไม่ใช่ข้อมูลที่ประกอบเอง
 */
trait BuildsTickets
{
    protected function department(string $name = 'Sales', bool $responsible = false): Department
    {
        return Department::create(['department_name' => $name, 'ticket_responsible' => $responsible]);
    }

    protected function itDepartment(): Department
    {
        return Department::query()->firstOrCreate(
            ['department_name' => 'IT Support'],
            ['ticket_responsible' => true],
        );
    }

    protected function staff(?Department $department = null, string $role = 'user', bool $head = false, bool $active = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => $active,
            'must_change_password' => false,
        ]);
    }

    protected function agent(?Department $department = null): User
    {
        return $this->staff($department ?? $this->itDepartment());
    }

    protected function override(User $user, bool $enabled, bool $alerts = true): TicketAgent
    {
        return TicketAgent::query()->forceCreate([
            'user_id' => $user->id,
            'is_enabled' => $enabled,
            'receives_alerts' => $alerts,
        ]);
    }

    protected function location(string $name = 'สำนักงานใหญ่', bool $active = true): TicketLocation
    {
        return TicketLocation::create(['name' => $name, 'is_active' => $active]);
    }

    protected function ticketFrom(User $requester, string $description = 'คอมพิวเตอร์เปิดไม่ติด'): Ticket
    {
        return app(TicketSubmissionService::class)->submitInternal($requester, [
            'description' => $description,
            'location_name' => 'ชั้น 2',
        ]);
    }

    /**
     * Ticket ที่บัญชีนี้แจ้งไว้ "ก่อน" ได้บทบาทปัจจุบัน — หัวหน้าแผนก / Handler / admin แจ้งเองไม่ได้แล้ว
     * (TicketAgentAccess::canReport) แต่ Ticket เก่าของเขายังอยู่และยังเป็นของเขา
     * จำลองตามจริง: ลดเป็นพนักงานทั่วไปชั่วคราว แจ้งผ่าน service จริง แล้วคืนบทบาทเดิม
     */
    protected function ticketFromBeforeRoleChange(User $requester, string $description = 'คอมพิวเตอร์เปิดไม่ติด'): Ticket
    {
        $original = $requester->only(['role', 'is_department_head']);
        $override = TicketAgent::query()->where('user_id', $requester->id)->first();

        $requester->forceFill(['role' => 'user', 'is_department_head' => false])->save();
        TicketAgent::query()->where('user_id', $requester->id)->delete();
        TicketAgent::query()->forceCreate(['user_id' => $requester->id, 'is_enabled' => false, 'receives_alerts' => true]);
        $requester->unsetRelation('ticketAgent');

        try {
            return $this->ticketFrom($requester, $description);
        } finally {
            $requester->forceFill($original)->save();
            TicketAgent::query()->where('user_id', $requester->id)->delete();
            if ($override) {
                TicketAgent::query()->forceCreate($override->only(['user_id', 'is_enabled', 'receives_alerts']));
            }
            $requester->unsetRelation('ticketAgent');
        }
    }

    protected function publicTicket(string $name = 'คุณลูกค้า'): Ticket
    {
        return app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => $name,
            'description' => 'ปริ้นเตอร์ใช้งานไม่ได้',
            'location_name' => 'สาขาบางนา',
        ], '203.0.113.5');
    }

    /** Ticket ที่ agent คนนี้รับงานแล้ว (สถานะ active และเป็นผู้เข้าร่วม) */
    protected function startedBy(User $agent, ?Ticket $ticket = null): Ticket
    {
        $ticket ??= $this->publicTicket();

        return app(TicketWorkflowService::class)->transition($ticket, Ticket::ACTION_START, $agent, Ticket::STATUS_NEW);
    }

    /** @param  array<string, mixed>  $payload */
    protected function transition(Ticket $ticket, string $action, User $actor, array $payload = []): Ticket
    {
        return app(TicketWorkflowService::class)->transition($ticket, $action, $actor, $ticket->fresh()->status, $payload);
    }
}
