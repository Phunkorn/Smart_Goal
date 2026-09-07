<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การจัดการหมวดงานของบันทึกงานประจำวัน (admin เท่านั้น)
 *
 * กติกาสำคัญคือหมวดที่ยังมีบันทึกงานใช้อยู่ต้องลบไม่ได้ แม้ FK จะตั้งเป็น
 * nullOnDelete ก็ตาม เพราะการลบจะทำให้บันทึกย้อนหลังกลายเป็น "ไม่ระบุหมวด"
 * ทั้งหมดเงียบ ๆ และรายงานที่เคยดูไว้จะเปลี่ยนไปโดยไม่มีร่องรอย
 */
class WorkLogCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_page_and_see_usage_counts(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, 'admin');
        $category = WorkLogCategory::query()->firstOrFail();

        $this->log($member, $department, ['work_log_category_id' => $category->id]);

        $this->actingAs($admin)
            ->get(route('admin.work-log-categories.index'))
            ->assertOk()
            ->assertSee('หมวดงานประจำวัน')
            ->assertSee($category->name)
            ->assertSee('ใช้อยู่ 1 รายการ');
    }

    public function test_admin_can_add_a_category_and_members_can_choose_it(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, 'admin');

        $this->actingAs($admin)
            ->post(route('admin.work-log-categories.store'), [
                'name' => 'Network',
                'tone' => 'teal',
                'icon' => 'bi-hdd-network',
                'sort_order' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_log_categories', [
            'name' => 'Network',
            'tone' => 'teal',
            'is_active' => true,
        ]);

        // หมวดใหม่ต้องโผล่ในตัวเลือกของหน้าบันทึกงานทันที
        $this->actingAs($member)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('Network');
    }

    public function test_duplicate_names_are_rejected(): void
    {
        $admin = $this->user(null, 'admin');
        $existing = WorkLogCategory::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.work-log-categories.store'), ['name' => $existing->name])
            ->assertSessionHasErrors('name');
    }

    public function test_an_invalid_icon_name_is_rejected(): void
    {
        $admin = $this->user(null, 'admin');

        $this->actingAs($admin)
            ->post(route('admin.work-log-categories.store'), [
                'name' => 'หมวดใหม่',
                'icon' => '<script>alert(1)</script>',
            ])
            ->assertSessionHasErrors('icon');

        $this->assertDatabaseMissing('work_log_categories', ['name' => 'หมวดใหม่']);
    }

    public function test_an_unknown_tone_is_rejected(): void
    {
        $admin = $this->user(null, 'admin');

        $this->actingAs($admin)
            ->post(route('admin.work-log-categories.store'), [
                'name' => 'หมวดสีแปลก',
                'tone' => 'neon',
            ])
            ->assertSessionHasErrors('tone');
    }

    public function test_admin_can_rename_and_deactivate_a_category(): void
    {
        $admin = $this->user(null, 'admin');
        $category = WorkLogCategory::query()->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.work-log-categories.update', $category), [
                'name' => 'IT Helpdesk',
                'tone' => 'purple',
                'sort_order' => 3,
                // ไม่ส่ง is_active มา = ปิดใช้งาน
            ])
            ->assertRedirect();

        $category->refresh();

        $this->assertSame('IT Helpdesk', $category->name);
        $this->assertSame('purple', $category->tone);
        $this->assertFalse($category->is_active);
    }

    /**
     * หมวดที่ปิดใช้งานต้องไม่ปรากฏเป็นตัวเลือกใหม่ แต่บันทึกเก่าที่ใช้หมวดนั้น
     * ต้องยังแสดงชื่อหมวดได้ตามปกติ
     */
    public function test_a_deactivated_category_disappears_from_the_picker_but_history_keeps_it(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $category = WorkLogCategory::query()->firstOrFail();

        $this->log($member, $department, [
            'work_log_category_id' => $category->id,
            'title' => 'งานที่ใช้หมวดนี้',
        ]);

        $category->update(['is_active' => false]);

        $response = $this->actingAs($member)->get(route('daily-logs.index'))->assertOk();

        // ยังเห็นชื่อหมวดในแถวของบันทึกเก่า
        $response->assertSee('งานที่ใช้หมวดนี้');
        // แต่ต้องไม่มีเป็น option ให้เลือกใหม่
        $response->assertDontSee('<option value="'.$category->id.'">'.$category->name.'</option>', false);
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, 'admin');
        $category = WorkLogCategory::query()->firstOrFail();

        $this->log($member, $department, ['work_log_category_id' => $category->id]);

        $this->actingAs($admin)
            ->delete(route('admin.work-log-categories.destroy', $category))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('work_log_categories', ['id' => $category->id]);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $admin = $this->user(null, 'admin');
        $category = WorkLogCategory::create(['name' => 'หมวดที่ไม่มีใครใช้', 'tone' => 'gray']);

        $this->actingAs($admin)
            ->delete(route('admin.work-log-categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('work_log_categories', ['id' => $category->id]);
    }

    public function test_non_admins_cannot_reach_the_page(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $category = WorkLogCategory::query()->firstOrFail();

        foreach ([
            $this->user($department),
            $this->user($department, 'user', true),
            $this->user($department, 'viewer'),
        ] as $actor) {
            $this->actingAs($actor)->get(route('admin.work-log-categories.index'))->assertForbidden();
            $this->actingAs($actor)
                ->post(route('admin.work-log-categories.store'), ['name' => 'ไม่ควรถูกสร้าง'])
                ->assertForbidden();
            $this->actingAs($actor)
                ->delete(route('admin.work-log-categories.destroy', $category))
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('work_log_categories', ['name' => 'ไม่ควรถูกสร้าง']);
    }

    public function test_the_sidebar_link_appears_for_admin_only(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $this->actingAs($this->user(null, 'admin'))
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee('หมวดงานประจำวัน');

        $this->actingAs($this->user($department))
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertDontSee('หมวดงานประจำวัน');
    }

    private function user(?Department $department, string $role = 'user', bool $head = false): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function log(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'งานทดสอบ',
            'duration_minutes' => 30,
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ], $overrides));
    }
}
