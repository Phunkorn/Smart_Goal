<?php

namespace Tests\Feature\Tickets;

use App\Services\TicketSchemaStatus;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smart Goals เดิมต้องใช้งานได้แม้ฐานข้อมูลยังไม่ได้ migrate ตารางของ Smart Ticket
 *
 * เหตุการณ์จริง: view composer ของ layout อ่าน ticket_agents ทุกหน้า (ป้ายจำนวน Ticket)
 * ฐาน dev ที่รันโค้ดใหม่ก่อน migrate จึง error ทุกหน้าของ admin และ user
 *
 * ตารางถูกลบภายในทรานแซกชันของ RefreshDatabase และย้อนกลับเองหลังจบเทสต์
 */
class TicketNotInstalledTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_existing_pages_still_open_before_the_ticket_migrations_run(): void
    {
        $sales = $this->department('Sales');
        $admin = $this->staff($sales, role: 'admin');
        $user = $this->staff($sales);
        $head = $this->staff($sales, head: true);
        $viewer = $this->staff($sales, role: 'viewer');

        $this->dropTicketTables();

        foreach ([
            'admin' => [$admin, route('board.index')],
            'user' => [$user, route('mytasks.index')],
            'department head' => [$head, route('mytasks.index')],
            'viewer' => [$viewer, route('board.index')],
            'user settings' => [$user, route('settings.index')],
            'user notifications' => [$user, route('notifications.index')],
        ] as $who => [$person, $page]) {
            $response = $this->actingAs($person)->get($page);
            $this->assertSame(200, $response->status(), "{$who}: {$page}");
            $response->assertDontSee('href="'.route('tickets.index').'"', false);
            $response->assertDontSee('href="'.route('admin.tickets.index').'"', false);
        }
    }

    public function test_ticket_pages_say_the_feature_is_not_installed_instead_of_crashing(): void
    {
        $user = $this->staff($this->department('Sales'));
        $admin = $this->staff($this->department('Management'), role: 'admin');

        $this->dropTicketTables();

        $this->get(route('support.create'))->assertStatus(503)->assertSee('Smart Ticket');
        $this->actingAs($user)->get(route('tickets.index'))->assertStatus(503);
        $this->actingAs($user)->get(route('tickets.create'))->assertStatus(503);
        $this->actingAs($admin)->get(route('admin.tickets.index'))->assertStatus(503);
    }

    public function test_once_installed_the_menu_and_count_come_back(): void
    {
        $this->publicTicket();

        $this->actingAs($this->agent())->get(route('mytasks.index'))
            ->assertSee('href="'.route('tickets.index').'"', false)
            ->assertSee('aria-label="รอรับงาน 1 รายการ"', false);
    }

    public function test_once_detected_the_check_costs_no_database_query(): void
    {
        $user = $this->staff($this->department('Sales'));
        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        // request ใหม่ (instance ใหม่ของ TicketSchemaStatus) — ผลบวกถูกจำไว้ใน cache store แล้ว
        $this->app->forgetScopedInstances();
        DB::enableQueryLog();
        $this->assertTrue(app(TicketSchemaStatus::class)->isInstalled());
        $schemaQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'sqlite_master') || str_contains($query['query'], 'information_schema'));
        DB::disableQueryLog();

        $this->assertCount(0, $schemaQueries);
    }

    public function test_a_missing_schema_is_not_remembered_and_migrations_reset_the_flag(): void
    {
        $status = app(TicketSchemaStatus::class);
        $this->assertTrue($status->isInstalled());

        $this->dropTicketTables();
        // ยังจำผลบวกเดิมอยู่จนกว่า migrate/rollback จะจบ — จำลองเหตุการณ์นั้น
        event(new MigrationsEnded('down'));
        $this->assertFalse($status->isInstalled());

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(TicketSchemaStatus::class)->isInstalled(), 'ผลลบต้องไม่ถูกจำค้างไว้');
        $this->assertNull(Cache::store(config('tickets.schema_cache_store'))->get('smart-ticket:installed:v2:sqlite::memory:'));
    }

    /**
     * ขึ้นโค้ดที่เขียน user_ref_id ก่อน migrate 2026_10_23_000008:
     * ตารางครบแต่คอลัมน์ใหม่ยังไม่มี และ cache ของโค้ดรุ่นก่อนยังจำว่า "ติดตั้งแล้ว"
     * ต้องได้หน้า "ยังไม่ติดตั้ง" (503) ไม่ใช่บันทึกล้มเป็น 500 ทุกครั้งที่มีคนกดรับงาน
     */
    public function test_code_deployed_before_the_identity_migration_reports_not_installed(): void
    {
        $user = $this->staff($this->department('Sales'));
        Cache::store(config('tickets.schema_cache_store'))->forever('smart-ticket:installed:sqlite::memory:', true);

        Schema::table('ticket_activities', function (Blueprint $table): void {
            $table->dropIndex('ticket_activities_user_ref_id_index');
            $table->dropColumn('user_ref_id');
        });

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(TicketSchemaStatus::class)->isInstalled());
        $this->actingAs($user)->get(route('tickets.index'))->assertStatus(503);
        $this->actingAs($user)->get(route('mytasks.index'))->assertOk()->assertDontSee('href="'.route('tickets.index').'"', false);
    }

    private function dropTicketTables(): void
    {
        foreach (['ticket_attachments', 'ticket_activities', 'ticket_participants', 'ticket_agents', 'tickets', 'ticket_locations'] as $table) {
            Schema::drop($table);
        }
    }
}
