<?php

namespace Tests\Feature;

use App\Models\JobImage;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Support\ProtectedMedia;
use App\Support\TrashRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ไฟล์ที่ลบต้องอยู่บนดิสก์จนกว่าจะถูกลบถาวร
 *
 * ระบบบอกผู้ใช้ว่าข้อมูลที่ถูกลบจะเก็บไว้ 30 วันก่อนลบถาวร แต่ไฟล์แนบเป็นข้อยกเว้น
 * ที่ไม่มีใครบอก คือถูกลบออกจากดิสก์ทันทีที่กดลบ ถังขยะจึงกู้ได้แค่แถวในฐานข้อมูล
 * ส่วนตัวไฟล์หายไปแล้วและไม่มีทางเรียกกลับมาได้เลย
 *
 * เทสต์ชุดนี้ตรึงสัญญาสามข้อ: ลบแล้วไฟล์ต้องอยู่, กู้คืนแล้วต้องเปิดได้, ลบถาวรแล้ว
 * ไฟล์ต้องหายจริงไม่ค้างเป็นขยะ
 */
class AttachmentTrashRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_deleting_a_task_attachment_keeps_the_file_on_disk(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);
        $attachment = $this->taskAttachment($task);

        $this->actingAs($owner)
            ->deleteJson(route('tasks.attachments.destroy', [$task->job_id, $attachment]))
            ->assertOk();

        $this->assertSoftDeleted('job_images', ['id' => $attachment->id]);
        $this->assertNotNull(
            ProtectedMedia::attachmentAbsolutePath($attachment->file_path),
            'ไฟล์ต้องยังอยู่บนดิสก์ให้กู้คืนได้'
        );
    }

    public function test_a_deleted_task_attachment_reaches_the_trash_and_comes_back(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);
        $attachment = $this->taskAttachment($task);

        $this->actingAs($owner)
            ->deleteJson(route('tasks.attachments.destroy', [$task->job_id, $attachment]))
            ->assertOk();

        $trash = TrashLog::where('entity_type', JobImage::class)->firstOrFail();
        $summary = TrashRetention::summary($trash);

        $this->assertTrue($summary['can_restore']);
        $this->assertTrue($summary['is_file']);
        $this->assertSame('ไฟล์แนบงาน', $summary['entity_label']);
        $this->assertSame('รายงาน.pdf', $summary['name']);

        TrashRetention::restore($trash);

        $this->assertDatabaseHas('job_images', ['id' => $attachment->id, 'deleted_at' => null]);
        $this->assertNotNull(ProtectedMedia::attachmentAbsolutePath($attachment->file_path));
    }

    public function test_purging_an_attachment_removes_the_file_for_real(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);
        $attachment = $this->taskAttachment($task);
        $path = $attachment->file_path;

        $this->actingAs($owner)
            ->deleteJson(route('tasks.attachments.destroy', [$task->job_id, $attachment]))
            ->assertOk();

        TrashRetention::purge(TrashLog::where('entity_type', JobImage::class)->firstOrFail());

        $this->assertDatabaseMissing('job_images', ['id' => $attachment->id]);
        $this->assertNull(
            ProtectedMedia::attachmentAbsolutePath($path),
            'ลบถาวรแล้วไฟล์ต้องหายจากดิสก์ ไม่ใช่ค้างเป็นขยะ'
        );
    }

    /**
     * FK cascadeOnDelete ลบแถวลูกให้จริงแต่ไม่ยิง Eloquent event
     *
     * การลบงานถาวรจึงเคยทิ้งไฟล์แนบไว้บนดิสก์โดยไม่มีอะไรอ้างถึงและไม่มีใครลบได้อีก
     */
    public function test_purging_a_task_takes_its_attachment_files_with_it(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin);
        $attachment = $this->taskAttachment($task);
        $path = $attachment->file_path;

        $this->actingAs($admin)
            ->delete(route('admin.tasks.destroy', $task->job_id));

        $trash = TrashLog::where('entity_type', WorkOrder::class)->firstOrFail();
        TrashRetention::purge($trash);

        $this->assertDatabaseMissing('work_orders', ['job_id' => $task->job_id]);
        $this->assertDatabaseMissing('job_images', ['id' => $attachment->id]);
        $this->assertNull(
            ProtectedMedia::attachmentAbsolutePath($path),
            'ลบงานถาวรต้องพาไฟล์แนบไปด้วย ไม่ใช่ทิ้งไว้เป็นไฟล์กำพร้า'
        );
    }

    /**
     * โปรเจกต์ไม่มี SoftDeletes แถวไฟล์แนบจึงถูก FK cascade ลบไปพร้อมกัน
     * แต่ตัวไฟล์ต้องรอด และการกู้คืนต้องสร้างแถวกลับมาชี้ไฟล์เดิม
     */
    public function test_deleting_a_project_keeps_its_attachment_files_and_restores_the_rows(): void
    {
        $owner = $this->user();
        $list = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'โปรเจกต์ทดสอบ', 'priority' => 2]);
        $attachment = WorkOrderListAttachment::create([
            'work_order_list_id' => $list->id,
            'file_path' => ProtectedMedia::storeAttachment(UploadedFile::fake()->create('สเปค.pdf', 12, 'application/pdf'), 'list-attachments/'.$list->id),
            'original_name' => 'สเปค.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $owner->id,
        ]);
        $path = $attachment->file_path;

        $this->actingAs($owner)
            ->deleteJson(route('mytasks.lists.destroy', $list))
            ->assertOk();

        $this->assertDatabaseMissing('work_order_list_attachments', ['id' => $attachment->id]);
        $this->assertNotNull(
            ProtectedMedia::attachmentAbsolutePath($path),
            'ลบโปรเจกต์แล้วไฟล์ต้องรอด เพราะตัวโปรเจกต์ยังกู้คืนได้'
        );

        TrashRetention::restore(TrashLog::where('entity_type', WorkOrderList::class)->firstOrFail());

        $this->assertDatabaseHas('work_order_lists', ['id' => $list->id]);
        $this->assertDatabaseHas('work_order_list_attachments', [
            'work_order_list_id' => $list->id,
            'file_path' => $path,
        ]);
    }

    /**
     * บัญชีกู้คืนได้ 30 วัน รูปโปรไฟล์จึงต้องรอดมาด้วย ไม่งั้นกู้มาได้บัญชีที่รูปพัง
     */
    public function test_deleting_an_account_keeps_its_profile_image_until_purge(): void
    {
        Storage::fake('public');

        // UserController::destroy เรียก UserSessionSecurity::invalidateAll() ซึ่งบังคับ
        // SESSION_DRIVER=database ส่วนชุดเทสต์ใช้ array การยิงผ่าน HTTP จึงโยน
        // LogicException ก่อนถึงโค้ดที่ต้องการตรวจ — ตั้ง driver ให้ตรงกับของจริงเฉพาะเทสต์นี้
        config(['session.driver' => 'database']);

        $admin = $this->user('admin');
        $victim = $this->user();
        $victim->forceFill(['profile_image' => 'profiles/avatar.jpg'])->save();
        Storage::disk('public')->put('profiles/avatar.jpg', 'x');

        $this->actingAs($admin)->delete(route('employees.destroy', $victim));

        $this->assertSoftDeleted('users', ['id' => $victim->id]);
        $this->assertTrue(
            Storage::disk('public')->exists('profiles/avatar.jpg'),
            'บัญชียังกู้คืนได้ รูปโปรไฟล์จึงต้องยังอยู่'
        );

        TrashRetention::purge(TrashLog::where('entity_type', User::class)->firstOrFail());

        $this->assertFalse(
            Storage::disk('public')->exists('profiles/avatar.jpg'),
            'ลบบัญชีถาวรแล้วรูปต้องหายไปด้วย'
        );
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function task(User $owner): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'งานที่มีไฟล์แนบ',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    private function taskAttachment(WorkOrder $task): JobImage
    {
        return JobImage::create([
            'job_id' => $task->job_id,
            'file_path' => ProtectedMedia::storeAttachment(
                UploadedFile::fake()->create('รายงาน.pdf', 12, 'application/pdf'),
                'job-attachments/'.$task->job_id
            ),
            'original_name' => 'รายงาน.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $task->user_id,
        ]);
    }
}
