<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\Meeting;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogAttachment;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Models\WorkOrderSubtask;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use App\Support\TrashRestorers;
use App\Support\TrashRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ถังขยะต้องรองรับทุกการลบ และต้องมีทางลบถาวร
 *
 * เดิมมีปัญหาสามชั้นซ้อนกัน: หลายชนิดข้อมูลลบแล้วหายถาวรทันทีโดยไม่ผ่านถังขยะเลย,
 * บันทึกงานประจำวันเข้าถังขยะแต่ TrashRetention::canRestore() ไม่รู้จักจึงกดกู้คืน
 * ไม่ได้ตลอดกาล, และไม่มีปุ่มลบถาวรเลยแม้แต่ปุ่มเดียว ผู้ดูแลระบบจึงต้องรอครบ
 * 30 วันเสมอแม้กับข้อมูลที่ต้องเอาออกทันที
 */
class TrashPurgeAndCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WorkLogService เขียนบันทึกงานประจำวันลงถังขยะมาตลอด แต่รายการที่กู้คืนได้
     * ไม่มีชนิดนี้อยู่ ผู้ใช้จึงเห็นแถวที่ไม่มีปุ่มกู้คืนโดยไม่มีคำอธิบาย
     */
    public function test_a_deleted_work_log_can_finally_be_restored(): void
    {
        $trash = $this->trash(WorkLog::class, 1);

        $this->assertTrue(TrashRetention::canRestore($trash));
        $this->assertSame('บันทึกงานประจำวัน', TrashRetention::summary($trash)['entity_label']);
    }

    /**
     * ทะเบียนต้องครอบคลุมทุกชนิดที่โค้ดเขียนลงถังขยะจริง
     *
     * ถ้ามีใครเพิ่ม AuditTrail::trash() ให้ชนิดใหม่โดยลืมขึ้นทะเบียน แถวนั้นจะโผล่
     * ในถังขยะแบบกู้คืนไม่ได้ ซึ่งเป็นความผิดพลาดที่ไม่มีอะไรฟ้องนอกจากเทสต์นี้
     */
    public function test_every_entity_the_app_sends_to_the_trash_is_registered(): void
    {
        $registered = TrashRestorers::all();

        foreach ([
            User::class,
            WorkOrder::class,
            WorkLog::class,
            Department::class,
            WorkOrderSubtask::class,
            Meeting::class,
            WorkOrderList::class,
            WorkspaceBoard::class,
            WorkLogCategory::class,
            WorkLogTemplate::class,
            JobImage::class,
            WorkOrderListAttachment::class,
            WorkLogAttachment::class,
            WorkspaceBoardAttachment::class,
        ] as $entity) {
            $this->assertContains($entity, $registered, $entity.' ยังไม่ได้ขึ้นทะเบียนใน TrashRestorers');
            $this->assertNotSame(
                class_basename($entity),
                TrashRestorers::labelFor($entity),
                $entity.' ยังไม่มีชื่อภาษาไทย ผู้ใช้จะเห็นชื่อคลาสดิบในตาราง'
            );
        }
    }

    public function test_an_admin_can_permanently_delete_one_item(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'ฝ่ายที่ถูกลบ']);
        $trash = $this->trash(Department::class, $department->id, ['department' => $department->attributesToArray()]);
        $department->delete();

        $this->actingAs($admin)
            ->delete(route('admin.trash.purge', $trash))
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $trash->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'purged']);
    }

    public function test_permanent_deletion_is_admin_only(): void
    {
        $trash = $this->trash(WorkLog::class, 1);

        foreach (['user', 'viewer'] as $role) {
            $this->actingAs($this->user($role))
                ->delete(route('admin.trash.purge', $trash))
                ->assertForbidden();

            $this->actingAs($this->user($role))
                ->delete(route('admin.trash.purge-expired'))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('trash_logs', ['id' => $trash->id]);
    }

    /**
     * "expired" ต้องเป็นปลายทางของตัวเอง ไม่ใช่ถูกอ่านเป็น id ของรายการ
     */
    public function test_the_bulk_purge_route_is_not_swallowed_by_the_single_item_route(): void
    {
        $admin = $this->user('admin');
        $expired = $this->trash(WorkLog::class, 1, null, -1);
        $stillSafe = $this->trash(WorkLog::class, 2, null, 20);

        $this->actingAs($admin)
            ->delete(route('admin.trash.purge-expired'))
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $expired->id]);
        $this->assertDatabaseHas('trash_logs', ['id' => $stillSafe->id]);
    }

    /**
     * การเปิดหน้าเพื่อดูต้องไม่ทำลายข้อมูล
     *
     * เดิมการเปิดแท็บถังขยะสั่งล้างของหมดอายุทันที ผู้ดูแลที่แค่เข้ามาดูจึงลบข้อมูล
     * ถาวรไปโดยไม่รู้ตัว และไม่มีทางย้อนกลับ
     */
    public function test_opening_the_trash_tab_does_not_destroy_anything(): void
    {
        $admin = $this->user('admin');
        $expired = $this->trash(WorkLog::class, 1, null, -5);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash']))
            ->assertOk();

        $this->assertDatabaseHas('trash_logs', ['id' => $expired->id]);
    }

    public function test_the_shortcut_filters_narrow_the_list(): void
    {
        $admin = $this->user('admin');
        $expiring = $this->trash(WorkLog::class, 1, null, 3);
        $comfortable = $this->trash(WorkLog::class, 2, null, 25);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash', 'shortcut' => 'expiring']))
            ->assertOk()
            ->assertViewHas('trashLogs', fn ($logs) => $logs->pluck('id')->all() === [$expiring->id]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash', 'shortcut' => 'files']))
            ->assertOk()
            ->assertViewHas('trashLogs', fn ($logs) => $logs->isEmpty());

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash']))
            ->assertOk()
            ->assertViewHas('trashLogs', fn ($logs) => $logs->count() === 2 && $comfortable->exists);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function trash(string $entity, int $id, ?array $payload = null, int $daysLeft = 30): TrashLog
    {
        return TrashLog::create([
            'entity_type' => $entity,
            'entity_id' => $id,
            'payload_json' => $payload ?? ['name' => 'รายการ '.$id],
            'deleted_by' => null,
            'deleted_at' => now()->subDay(),
            'purge_after' => now()->addDays($daysLeft),
        ]);
    }
}
