<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\TicketParticipantService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * down() ของ migration Smart Ticket ต้องหยุดเมื่อมีข้อมูล แทนการลบเงียบ ๆ (CLAUDE.md)
 * และย้อนกลับได้ตามปกติเมื่อยังไม่มีข้อมูล
 */
class TicketMigrationRollbackTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    private const IDENTITY = '2026_10_23_000008_add_identity_refs_to_ticket_history';

    private const PUBLIC_CONTACT = '2026_10_24_000001_add_public_contact_fields_to_tickets_table';

    private const MIGRATIONS = [
        self::PUBLIC_CONTACT,
        self::IDENTITY,
        '2026_10_23_000007_add_ticket_responsible_to_departments_table',
        '2026_10_23_000006_create_ticket_agents_table',
        '2026_10_23_000005_create_ticket_attachments_table',
        '2026_10_23_000004_create_ticket_activities_table',
        '2026_10_23_000003_create_ticket_participants_table',
        '2026_10_23_000002_create_tickets_table',
        '2026_10_23_000001_create_ticket_locations_table',
    ];

    public function test_every_down_refuses_to_drop_existing_data(): void
    {
        $agent = $this->agent();
        $this->override($this->staff($this->department('Ops')), enabled: true);
        $this->location('สาขา 1');
        $ticket = $this->startedBy($agent);
        // รหัสรีโมทและบัญชี LINE ของผู้แจ้งสาธารณะ — หายถ้าลบคอลัมน์
        DB::table('tickets')->where('id', $ticket->id)->update(['remote_access' => '123 456 789', 'line_user_id' => 'U0001']);
        // ผู้ช่วยที่บัญชีถูกลบถาวร — ตัวตนของแถวนี้เหลืออยู่ที่ user_ref_id เท่านั้น
        $helper = $this->agent();
        app(TicketParticipantService::class)->join($ticket, $helper);
        $helper->forceDelete();
        DB::table('ticket_attachments')->insert([
            'ticket_id' => $ticket->id,
            'file_path' => 'ticket-attachments/'.$ticket->id.'/a.jpg',
            'original_name' => 'a.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::MIGRATIONS as $name) {
            try {
                $this->migration($name)->down();
                $this->fail("{$name} dropped data without stopping");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ไม่สามารถถอยกลับ', $e->getMessage(), $name);
            }
        }

        $this->assertTrue(Schema::hasTable('tickets'));
        $this->assertTrue(Schema::hasColumn('departments', 'ticket_responsible'));
        $this->assertTrue(Schema::hasColumn('ticket_participants', 'user_ref_id'));
        $this->assertTrue(Schema::hasColumn('tickets', 'line_user_id'));
    }

    public function test_the_public_contact_columns_roll_back_and_return_when_empty(): void
    {
        $this->migration(self::PUBLIC_CONTACT)->down();
        $this->assertFalse(Schema::hasColumn('tickets', 'remote_access'));
        $this->assertFalse(Schema::hasColumn('tickets', 'line_user_id'));
        $this->assertFalse(Schema::hasColumn('tickets', 'line_display_name'));

        $this->migration(self::PUBLIC_CONTACT)->up();
        $this->assertTrue(Schema::hasColumn('tickets', 'line_display_name'));
    }

    public function test_identity_refs_are_backfilled_only_from_ids_that_still_exist(): void
    {
        $kept = $this->agent();
        $purged = $this->agent();
        $keptTicket = $this->transition($this->startedBy($kept), Ticket::ACTION_CLOSE, $kept, ['note' => 'จบ']);
        $purgedTicket = $this->transition($this->startedBy($purged), Ticket::ACTION_CLOSE, $purged, ['note' => 'จบ']);

        // สภาพก่อน migration: ยังไม่มี ref แล้วบัญชีหนึ่งถูกลบถาวร
        DB::table('ticket_participants')->update(['user_ref_id' => null]);
        DB::table('ticket_activities')->update(['user_ref_id' => null]);
        DB::table('tickets')->update(['closed_by_ref_id' => null]);
        $purged->forceDelete();

        $this->migration(self::IDENTITY)->down();
        $this->assertFalse(Schema::hasColumn('ticket_activities', 'user_ref_id'));
        $this->migration(self::IDENTITY)->up();

        $this->assertSame([$kept->id], DB::table('ticket_participants')->where('ticket_id', $keptTicket->id)->pluck('user_ref_id')->all());
        $this->assertSame([null], DB::table('ticket_participants')->where('ticket_id', $purgedTicket->id)->pluck('user_ref_id')->all(), 'ไม่เดาค่าให้แถวที่ไม่มี user_id แล้ว');
        $this->assertSame($kept->id, (int) DB::table('tickets')->where('id', $keptTicket->id)->value('closed_by_ref_id'));
        $this->assertNull(DB::table('tickets')->where('id', $purgedTicket->id)->value('closed_by_ref_id'));
        $this->assertTrue(DB::table('ticket_activities')->where('ticket_id', $keptTicket->id)->whereNotNull('user_id')
            ->get(['user_id', 'user_ref_id'])->every(fn ($row) => (int) $row->user_id === (int) $row->user_ref_id));
        $this->assertSame(0, DB::table('ticket_activities')->where('ticket_id', $purgedTicket->id)->whereNotNull('user_ref_id')->count());
        // ผู้แจ้งภายนอก (activity "created") ไม่มีผู้ใช้ ref จึงเป็น null
        $this->assertNull(DB::table('ticket_activities')->where('ticket_id', $keptTicket->id)->where('type', 'created')->value('user_ref_id'));
    }

    public function test_an_empty_install_rolls_back_cleanly(): void
    {
        foreach (self::MIGRATIONS as $name) {
            $this->migration($name)->down();
        }

        $this->assertFalse(Schema::hasTable('tickets'));
        $this->assertFalse(Schema::hasTable('ticket_locations'));
        $this->assertFalse(Schema::hasColumn('departments', 'ticket_responsible'));
    }

    private function migration(string $name): Migration
    {
        return require database_path('migrations/'.$name.'.php');
    }
}
