<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * นัดหมาย — คนละ domain กับการประชุม
 *
 * นัดหมายไม่มีผู้เข้าร่วมและไม่มีการตอบรับ ภาษาบนหน้าจอจึงต้องไม่พูดถึงการประชุมเลย
 * สิทธิ์ทั้งหมดมาจาก AppointmentPolicy ไม่ใช่การซ่อนปุ่มใน Blade
 */
class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'IT']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_member_can_create_an_appointment_owned_by_themselves(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->post(route('appointments.store'), $this->payload())
            ->assertRedirect(route('appointments.show', Appointment::sole()))
            ->assertSessionHas('meeting_success');

        $appointment = Appointment::sole();
        $this->assertSame('นัดหมอฟัน', $appointment->title);
        $this->assertSame('2026-10-20', $appointment->appointment_date->format('Y-m-d'));
        $this->assertSame('09:30', substr((string) $appointment->starts_at_time, 0, 5));
        $this->assertSame('10:30', substr((string) $appointment->ends_at_time, 0, 5));
        // created_by ต้องมาจากผู้ล็อกอิน ไม่ใช่จากฟอร์ม
        $this->assertSame((int) $owner->id, (int) $appointment->created_by);
    }

    public function test_a_crafted_owner_in_the_request_is_ignored(): void
    {
        $owner = $this->user();
        $victim = $this->user();

        $this->actingAs($owner)
            ->post(route('appointments.store'), $this->payload(['created_by' => $victim->id]))
            ->assertRedirect();

        $this->assertSame((int) $owner->id, (int) Appointment::sole()->created_by);
    }

    public function test_an_appointment_requires_a_title_a_date_and_a_start_time(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->post(route('appointments.store'), ['location' => 'โรงพยาบาล'])
            ->assertSessionHasErrors(['title', 'appointment_date', 'starts_at_time']);

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_end_time_must_follow_the_start_time(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->post(route('appointments.store'), $this->payload([
                'starts_at_time' => '11:00',
                'ends_at_time' => '10:00',
            ]))
            ->assertSessionHasErrors('ends_at_time');

        $this->assertSame(0, Appointment::count());
    }

    public function test_an_appointment_without_an_end_time_is_accepted(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->post(route('appointments.store'), $this->payload(['ends_at_time' => null]))
            ->assertRedirect();

        $this->assertNull(Appointment::sole()->ends_at_time);
    }

    public function test_the_owner_sees_the_appointment_detail_in_appointment_language(): void
    {
        $owner = $this->user();
        $appointment = $this->appointment($owner);

        $this->actingAs($owner)
            ->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertSee('นัดหมอฟัน')
            ->assertSee('นัดหมาย')
            ->assertSee('รายละเอียดนัดหมาย')
            // วันที่ต้องเป็นปี พ.ศ. เหมือนทุกหน้าในกลุ่มนี้
            ->assertSee('2569')
            ->assertSee('แก้ไข')
            ->assertSee('ลบ')
            // ผู้สร้างดูสถานะของผู้ร่วมได้เหมือนผู้สร้างประชุม
            ->assertSee('สถานะผู้เข้าร่วม')
            ->assertSee('ผู้ร่วมนัดหมาย')
            // แต่ถ้อยคำทั้งหมดต้องเป็นภาษาของนัดหมาย ไม่ใช่ของการประชุม
            ->assertDontSee('ผู้เข้าร่วมประชุม')
            ->assertDontSee('ตอบรับประชุม')
            ->assertDontSee('ไม่ว่างประชุม')
            ->assertDontSee('เข้าร่วมประชุม')
            ->assertDontSee('การประชุม');
    }

    public function test_only_the_owner_can_view_an_appointment(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $admin = $this->user('admin');
        $appointment = $this->appointment($owner);

        foreach ([$stranger, $admin] as $intruder) {
            $this->actingAs($intruder)
                ->get(route('appointments.show', $appointment))
                ->assertForbidden();
        }
    }

    public function test_the_owner_can_update_their_appointment(): void
    {
        $owner = $this->user();
        $appointment = $this->appointment($owner);

        $this->actingAs($owner)
            ->patch(route('appointments.update', $appointment), $this->payload([
                'title' => 'เลื่อนนัดหมอฟัน',
                'appointment_date' => '2026-10-22',
            ]))
            ->assertRedirect(route('appointments.show', $appointment))
            ->assertSessionHas('meeting_success');

        $appointment->refresh();
        $this->assertSame('เลื่อนนัดหมอฟัน', $appointment->title);
        $this->assertSame('2026-10-22', $appointment->appointment_date->format('Y-m-d'));
    }

    public function test_nobody_else_can_update_or_delete_an_appointment(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $admin = $this->user('admin');
        $appointment = $this->appointment($owner);

        foreach ([$stranger, $admin] as $intruder) {
            $this->actingAs($intruder)
                ->patch(route('appointments.update', $appointment), $this->payload(['title' => 'แก้ของคนอื่น']))
                ->assertForbidden();

            $this->actingAs($intruder)
                ->delete(route('appointments.destroy', $appointment))
                ->assertForbidden();
        }

        $this->assertSame('นัดหมอฟัน', $appointment->fresh()->title);
        $this->assertNull($appointment->fresh()->deleted_at);
    }

    public function test_the_owner_can_delete_their_appointment_and_it_leaves_the_calendar(): void
    {
        $owner = $this->user();
        $appointment = $this->appointment($owner);

        $this->actingAs($owner)
            ->delete(route('appointments.destroy', $appointment))
            ->assertRedirect(route('mytasks.index', ['view' => 'meeting']))
            ->assertSessionHas('meeting_success');

        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);

        $this->assertSame([], $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->viewData('meetingCalendarData')['calendarEvents']
            ->where('type', 'appointment')
            ->pluck('title')
            ->all());
    }

    public function test_a_viewer_can_neither_own_nor_create_appointments(): void
    {
        $viewer = $this->user('viewer');

        // viewer เป็นบทบาทอ่านอย่างเดียวและห้ามเป็นเจ้าของข้อมูล (CLAUDE.md)
        $this->actingAs($viewer)
            ->post(route('appointments.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Appointment::count());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นัดหมอฟัน',
            'appointment_date' => '2026-10-20',
            'starts_at_time' => '09:30',
            'ends_at_time' => '10:30',
            'location' => 'โรงพยาบาล',
            'description' => 'ตรวจฟันประจำปี',
            // ขอบเขตการมองเห็นเป็นช่องบังคับของฟอร์มนัดหมาย ไม่มีค่าเริ่มต้นฝั่งเซิร์ฟเวอร์
            // โดยตั้งใจ (ดู AppointmentController::validatedAppointment)
            // ค่าตั้งต้นของชุดทดสอบนี้เป็น "ส่วนตัว" ซึ่งเป็นพฤติกรรมเดิมของนัดหมายทุกใบ
            'visibility' => Appointment::VISIBILITY_PERSONAL,
        ], $overrides);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function appointment(User $owner): Appointment
    {
        $appointment = new Appointment($this->payload());
        $appointment->created_by = $owner->id;
        $appointment->save();

        return $appointment;
    }
}
