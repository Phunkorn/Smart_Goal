<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketIdentityAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * id ผู้ใช้ที่ถูกนำกลับมาใช้ซ้ำทำให้ประวัติของคนเก่าถูกนับรวมกับคนใหม่ (ref ไม่มี FK)
 * ตรวจจับได้จาก "ประวัติเกิดก่อนบัญชีที่ถือ id นั้นถูกสร้าง" — tickets:identity-check
 */
class TicketIdentityAuditTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_normal_history_reports_no_collision(): void
    {
        $agent = $this->agent();
        $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->agent()->forceDelete();

        $this->assertCount(0, app(TicketIdentityAudit::class)->collisions());
        $this->artisan('tickets:identity-check')->assertExitCode(0);
    }

    public function test_a_reused_user_id_is_detected(): void
    {
        $this->travelTo(now()->subDays(30));
        $old = $this->agent();
        $oldId = $old->id;
        $this->transition($this->startedBy($old), Ticket::ACTION_CLOSE, $old, ['note' => 'จบ']);
        $this->travelBack();

        // บัญชีเดิมถูกลบถาวร แล้ว id เดียวกันถูกจ่ายให้บัญชีใหม่ (เช่น ตัวนับ AUTO_INCREMENT ถูกตั้งใหม่)
        $old->forceDelete();
        $newcomer = User::factory()->create(['id' => $oldId, 'name' => 'พนักงานใหม่', 'must_change_password' => false]);

        $collisions = app(TicketIdentityAudit::class)->collisions();

        $this->assertSame([$oldId], $collisions->pluck('ref')->all());
        $this->assertSame('พนักงานใหม่', $collisions->first()['account']);
        $this->assertTrue($collisions->first()['first_history_at']->lt($newcomer->created_at));
        $this->artisan('tickets:identity-check')->assertExitCode(1);
    }
}
