<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * กติกาสิทธิ์ของประกาศ: เฉพาะหัวหน้าแผนกจัดการได้ แก้/ลบได้เฉพาะของตัวเอง
 * และไม่มี admin bypass ตามที่เจ้าของระบบกำหนด
 */
class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
    }

    public function test_only_department_heads_can_open_and_create_announcements(): void
    {
        $head = $this->user('user', $this->it, ['is_department_head' => true]);

        $this->assertTrue($head->can('viewAny', Announcement::class));
        $this->assertTrue($head->can('create', Announcement::class));

        foreach ([
            'employee' => $this->user('user', $this->it),
            'admin' => $this->user('admin'),
            'viewer' => $this->user('viewer'),
            'inactive head' => $this->user('user', $this->it, ['is_department_head' => true, 'is_active' => false]),
        ] as $label => $user) {
            $this->assertFalse($user->can('viewAny', Announcement::class), $label.' must not open announcements');
            $this->assertFalse($user->can('create', Announcement::class), $label.' must not create announcements');
        }
    }

    public function test_a_head_manages_only_their_own_announcements(): void
    {
        $head = $this->user('user', $this->it, ['is_department_head' => true]);
        $coHead = $this->user('user', $this->it, ['is_department_head' => true]);
        $otherHead = $this->user('user', $this->hr, ['is_department_head' => true]);
        $announcement = $this->announcement($head);

        $this->assertTrue($head->can('update', $announcement));
        $this->assertTrue($head->can('delete', $announcement));

        // หัวหน้าอีกคนในแผนกเดียวกันหรือแผนกอื่นไม่ใช่เจ้าของ
        foreach ([$coHead, $otherHead] as $user) {
            $this->assertFalse($user->can('update', $announcement));
            $this->assertFalse($user->can('delete', $announcement));
        }
    }

    public function test_admin_has_no_bypass(): void
    {
        $announcement = $this->announcement($this->user('user', $this->it, ['is_department_head' => true]));
        $admin = $this->user('admin');

        $this->assertFalse($admin->can('update', $announcement));
        $this->assertFalse($admin->can('delete', $announcement));
    }

    public function test_demoted_or_moved_heads_lose_management_rights(): void
    {
        $head = $this->user('user', $this->it, ['is_department_head' => true]);
        $announcement = $this->announcement($head);

        $head->update(['is_department_head' => false]);
        $this->assertFalse($head->fresh()->can('update', $announcement));

        $head->update(['is_department_head' => true, 'department_id' => $this->hr->id]);
        $this->assertFalse($head->fresh()->can('update', $announcement));
        $this->assertFalse($head->fresh()->can('delete', $announcement));
    }

    private function announcement(User $author): Announcement
    {
        $announcement = new Announcement([
            'title' => 'ปิดปรับปรุงระบบ',
            'body' => 'รายละเอียด',
            'audience' => Announcement::AUDIENCE_DEPARTMENT,
            'starts_on' => now()->format('Y-m-d'),
        ]);
        $announcement->created_by = $author->id;
        $announcement->department_id = $author->department_id;
        $announcement->save();

        return $announcement;
    }

    private function user(string $role, ?Department $department = null, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
