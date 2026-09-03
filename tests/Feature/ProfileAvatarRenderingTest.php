<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หลายหน้าเคยแสดงตัวย่อชื่อแทนรูปโปรไฟล์ตลอดเวลา เพราะลืมเช็ค profile_image
 * ทั้งที่ผู้ใช้อัปโหลดรูปไว้แล้ว (ผู้ร่วมงานบนการ์ดบอร์ด ผู้รับผิดชอบบนการ์ด kanban
 * รายชื่อผู้เข้าร่วมประชุม และรายชื่อในโมดัลมอบหมายงานของผู้ดูแลระบบ)
 *
 * ตอนนี้ทุกจุดใช้ partial components.user-avatar-content ตัวเดียวกัน
 */
class ProfileAvatarRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_and_kanban_cards_show_the_real_photo_of_the_assignee_and_collaborators(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->userWithPhoto($department, 'profiles/owner.png');
        $collaborator = $this->userWithPhoto($department, 'profiles/mate.png');

        $task = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $department->id,
            'job_topic' => 'งานที่มีผู้ร่วมงาน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $task->collaborators()->attach($collaborator->id, [
            'status' => 'accepted',
            'added_by' => $owner->id,
        ]);

        $this->actingAs($owner)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee(route('media.profile', $owner), false)
            ->assertSee(route('media.profile', $collaborator), false);
    }

    public function test_meeting_attendee_list_shows_the_real_photo(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $organizer = $this->userWithPhoto($department, 'profiles/organizer.png');
        $attendee = $this->userWithPhoto($department, 'profiles/attendee.png');

        $meeting = Meeting::create([
            'title' => 'ประชุมทีม',
            'created_by' => $organizer->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $meeting->attendees()->attach($attendee->id);

        $this->actingAs($organizer)->get(route('meetings.index'))
            ->assertOk()
            ->assertSee(route('media.profile', $attendee), false);
    }

    public function test_admin_assignment_modal_lists_people_with_their_real_photo(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $employee = $this->userWithPhoto($department, 'profiles/employee.png');
        $admin = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('board.index'))
            ->assertOk()
            ->assertSee(route('media.profile', $employee), false);
    }

    private function userWithPhoto(Department $department, string $path): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'profile_image' => $path,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
