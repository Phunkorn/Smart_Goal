<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ขอบเขตของ "ปฏิทินประชุมและนัดหมาย" — ระดับแผนก ไม่ใช่ปฏิทินส่วนตัว
 *
 * สิ่งที่ไฟล์นี้ตรึงไว้ เพราะพังเงียบได้และพังแล้วคือข้อมูลรั่ว:
 *   1. ทุกบทบาทในแผนกเห็นประชุมของแผนกตัวเอง แม้ไม่ได้ถูกเชิญ
 *   2. ผู้ที่ถูกเชิญข้ามแผนกเห็น "เฉพาะใบที่ถูกเชิญ" ไม่ใช่ข้อมูลอื่นของแผนกนั้น
 *   3. นัดหมายที่เป็น "ส่วนตัว" ต้องไม่ถูกเปิดเผยให้คนในแผนกเลย — รวมถึง
 *      นัดหมายเก่าทั้งหมดที่ถูกบันทึกไว้ตอนที่ระบบยังสัญญาว่าเป็นส่วนตัว
 *   4. การเห็นข้ามแผนกไม่ได้แปลว่าแก้หรือลบได้
 */
class DepartmentCalendarScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Department $otherDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'IT']);
        $this->otherDepartment = Department::create(['department_name' => 'Finance']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /* ---------- ข้อ 1: ทุกบทบาทเห็นของแผนกตัวเอง ---------- */

    public function test_every_role_in_the_department_sees_department_meetings_without_being_invited(): void
    {
        $organizer = $this->user($this->department);
        $this->meeting($organizer, 'ประชุมแผนกไอที');

        // พนักงานร่วมแผนก, หัวหน้าแผนก และ viewer ที่สังกัดแผนกเดียวกัน
        foreach ([
            $this->user($this->department),
            $this->user($this->department, head: true),
            $this->user($this->department, role: 'viewer'),
        ] as $teammate) {
            $this->assertContains(
                'ประชุมแผนกไอที',
                $this->calendarTitles($teammate),
                'สมาชิกแผนกบทบาท '.$teammate->role.' ต้องเห็นประชุมของแผนกตัวเอง'
            );
        }
    }

    public function test_a_department_scoped_appointment_is_visible_to_the_whole_department(): void
    {
        $owner = $this->user($this->department);
        $this->appointment($owner, 'นัดวางระบบเครือข่าย', Appointment::VISIBILITY_DEPARTMENT);

        $teammate = $this->user($this->department);

        $this->assertContains('นัดวางระบบเครือข่าย', $this->calendarTitles($teammate));
    }

    /* ---------- ข้อ 2: ข้ามแผนกเห็นเฉพาะที่ถูกเชิญ ---------- */

    public function test_a_cross_department_invitee_sees_only_the_meeting_they_were_invited_to(): void
    {
        $organizer = $this->user($this->department);
        $invitedMeeting = $this->meeting($organizer, 'ประชุมร่วมสองแผนก');
        $this->meeting($organizer, 'ประชุมภายในแผนกไอที');

        $outsider = $this->user($this->otherDepartment);
        $invitedMeeting->attendees()->attach($outsider);

        $titles = $this->calendarTitles($outsider);

        $this->assertContains('ประชุมร่วมสองแผนก', $titles);
        // ถูกเชิญหนึ่งใบ ต้องไม่ได้สิทธิ์อ่านตารางทั้งแผนกของเจ้าภาพไปด้วย
        $this->assertNotContains('ประชุมภายในแผนกไอที', $titles);
    }

    public function test_a_meeting_is_owned_by_the_organizer_department_not_by_the_attendee_department(): void
    {
        $outsideOrganizer = $this->user($this->otherDepartment);
        $meeting = $this->meeting($outsideOrganizer, 'ประชุมของฝ่ายการเงิน');

        // คนในแผนกเราหนึ่งคนถูกเชิญไปร่วม
        $invitedTeammate = $this->user($this->department);
        $meeting->attendees()->attach($invitedTeammate);

        // แต่เพื่อนร่วมแผนกคนอื่นที่ไม่ได้ถูกเชิญต้องไม่เห็น เพราะประชุมใบนี้
        // ไม่ใช่ของแผนกเรา การนับแผนกของผู้เข้าร่วมจะเปิดข้อมูลของอีกแผนกทั้งใบ
        $bystander = $this->user($this->department);

        $this->assertContains('ประชุมของฝ่ายการเงิน', $this->calendarTitles($invitedTeammate));
        $this->assertNotContains('ประชุมของฝ่ายการเงิน', $this->calendarTitles($bystander));
    }

    /* ---------- ข้อ 3: นัดหมายส่วนตัวต้องไม่รั่ว ---------- */

    public function test_a_personal_appointment_stays_hidden_from_the_department(): void
    {
        $owner = $this->user($this->department);
        $this->appointment($owner, 'นัดหมอฟันส่วนตัว', Appointment::VISIBILITY_PERSONAL);

        $teammate = $this->user($this->department);
        $head = $this->user($this->department, head: true);
        $admin = $this->user($this->department, role: 'admin');

        foreach ([$teammate, $head, $admin] as $otherPerson) {
            $this->assertNotContains(
                'นัดหมอฟันส่วนตัว',
                $this->calendarTitles($otherPerson),
                'นัดหมายส่วนตัวต้องไม่ถูกเปิดให้บทบาท '.$otherPerson->role
            );

            $this->actingAs($otherPerson)
                ->get(route('appointments.show', Appointment::query()->firstOrFail()))
                ->assertForbidden();
        }

        // เจ้าของยังเห็นของตัวเองตามปกติ
        $this->assertContains('นัดหมอฟันส่วนตัว', $this->calendarTitles($owner));
    }

    public function test_appointments_created_before_the_department_scope_feature_default_to_personal(): void
    {
        $owner = $this->user($this->department);

        /*
         * นัดหมายเก่าคือแถวที่ไม่ได้ระบุ visibility เลย ซึ่งต้องตกเป็น 'personal'
         * จากค่า default ของคอลัมน์ ไม่ใช่ 'department' — นี่คือกติกาที่กัน
         * การเปิดเผยย้อนหลังตอน deploy
         */
        $legacy = new Appointment([
            'title' => 'นัดหมายเก่าก่อนมีฟีเจอร์',
            'appointment_date' => '2026-10-15',
            'starts_at_time' => '09:30',
        ]);
        $legacy->created_by = $owner->id;
        $legacy->department_id = $this->department->id;
        $legacy->save();

        $this->assertSame(Appointment::VISIBILITY_PERSONAL, $legacy->fresh()->visibility);
        $this->assertNotContains(
            'นัดหมายเก่าก่อนมีฟีเจอร์',
            $this->calendarTitles($this->user($this->department))
        );
    }

    public function test_a_user_without_a_department_does_not_see_other_unassigned_peoples_items(): void
    {
        // ผู้ใช้ที่ยังไม่ถูกจัดแผนกต้องไม่จับคู่ department_id null กับ null ของคนอื่น
        $strayOrganizer = $this->user(null);
        $this->meeting($strayOrganizer, 'ประชุมของคนไม่มีแผนก');
        $this->appointment($strayOrganizer, 'นัดหมายของคนไม่มีแผนก', Appointment::VISIBILITY_DEPARTMENT);

        $otherStray = $this->user(null);
        $titles = $this->calendarTitles($otherStray);

        $this->assertNotContains('ประชุมของคนไม่มีแผนก', $titles);
        $this->assertNotContains('นัดหมายของคนไม่มีแผนก', $titles);
    }

    /* ---------- ข้อ 4: เห็นได้ ≠ แก้ได้ ---------- */

    public function test_department_visibility_does_not_grant_edit_or_delete_rights(): void
    {
        $organizer = $this->user($this->department);
        $meeting = $this->meeting($organizer, 'ประชุมแผนก');
        $appointment = $this->appointment($organizer, 'นัดหมายของแผนก', Appointment::VISIBILITY_DEPARTMENT);

        $teammate = $this->user($this->department);

        // เห็นได้จริง
        $this->actingAs($teammate)->get(route('meetings.show', $meeting))->assertOk();
        $this->actingAs($teammate)->get(route('appointments.show', $appointment))->assertOk();

        // แต่แก้และลบไม่ได้
        $this->actingAs($teammate)->delete(route('meetings.destroy', $meeting))->assertForbidden();
        $this->actingAs($teammate)->delete(route('appointments.destroy', $appointment))->assertForbidden();
        $this->actingAs($teammate)
            ->patch(route('appointments.update', $appointment), [
                'title' => 'ชื่อที่ถูกแก้โดยคนที่ไม่ใช่เจ้าของ',
                'appointment_date' => '2026-10-16',
                'starts_at_time' => '11:00',
                'visibility' => Appointment::VISIBILITY_DEPARTMENT,
            ])
            ->assertForbidden();

        $this->assertSame('นัดหมายของแผนก', $appointment->fresh()->title);
    }

    /* ---------- เจ้าของแผนกของนัดหมายถูกกำหนดฝั่งเซิร์ฟเวอร์ ---------- */

    public function test_the_owning_department_comes_from_the_creator_not_from_the_request(): void
    {
        $owner = $this->user($this->department);

        $this->actingAs($owner)
            ->post(route('appointments.store'), [
                'title' => 'นัดหมายที่ปลอมแผนกมา',
                'appointment_date' => '2026-10-15',
                'starts_at_time' => '09:30',
                'visibility' => Appointment::VISIBILITY_DEPARTMENT,
                // พยายามยัดแผนกอื่นมากับคำขอ
                'department_id' => $this->otherDepartment->id,
            ])
            ->assertRedirect();

        $this->assertSame(
            $this->department->id,
            Appointment::query()->firstOrFail()->department_id
        );
    }

    public function test_an_unknown_visibility_value_is_rejected(): void
    {
        $owner = $this->user($this->department);

        $this->actingAs($owner)
            ->post(route('appointments.store'), [
                'title' => 'นัดหมาย',
                'appointment_date' => '2026-10-15',
                'starts_at_time' => '09:30',
                'visibility' => 'everyone',
            ])
            ->assertSessionHasErrors('visibility');

        $this->assertSame(0, Appointment::count());
    }

    /* ---------- helpers ---------- */

    /**
     * ชื่อรายการทั้งหมดที่ผู้ใช้คนนี้เห็นบนปฏิทิน ผ่าน endpoint จริงของหน้า
     *
     * ถามผ่าน HTTP ไม่ใช่เรียก service ตรง ๆ เพื่อให้ชั้นตรวจสิทธิ์ของ route
     * และ controller ถูกนับรวมในการทดสอบด้วย
     *
     * @return array<int, string>
     */
    private function calendarTitles(User $viewer): array
    {
        $response = $this->actingAs($viewer)
            ->getJson(route('meeting-calendar.events', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'type' => 'all',
            ]))
            ->assertOk();

        return array_column($response->json('events'), 'title');
    }

    private function user(?Department $department, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function meeting(User $creator, string $title, string $startsAt = '2026-10-15 10:00'): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse($startsAt, MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse($startsAt, MeetingQueryService::BUSINESS_TIMEZONE)->addHour()->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }

    private function appointment(User $owner, string $title, string $visibility): Appointment
    {
        $appointment = new Appointment([
            'title' => $title,
            'appointment_date' => '2026-10-15',
            'starts_at_time' => '09:30',
            'ends_at_time' => '10:30',
            'location' => 'โรงพยาบาล',
            'visibility' => $visibility,
        ]);
        $appointment->created_by = $owner->id;
        $appointment->department_id = $owner->department_id;
        $appointment->save();

        return $appointment;
    }
}
