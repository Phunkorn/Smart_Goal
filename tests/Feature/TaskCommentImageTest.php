<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderUpdateAttachment;
use App\Services\TaskCommentService;
use App\Support\ProtectedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * แนบรูปในความคิดเห็นของงาน
 *
 * เดิม work_order_updates มีแต่คอลัมน์ note การส่งภาพหน้าจอประกอบคำถามจึงต้องไป
 * แนบเป็นไฟล์อ้างอิงของงานแทน ซึ่งปนกับเอกสารส่งมอบงานจริงและไม่ผูกกับบทสนทนา
 *
 * รูปอยู่ใน private disk เสิร์ฟผ่าน MediaController ที่ตรวจสิทธิ์ "อ่านความคิดเห็น"
 * ทุกครั้ง ไม่ใช่ URL สาธารณะ
 */
class TaskCommentImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_participant_can_post_a_comment_with_images(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $response = $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $task), [
                'message' => 'ตามภาพครับ',
                'images' => [$this->png('หน้าจอ.png'), $this->png('ผลลัพธ์.png')],
            ])
            ->assertCreated();

        $this->assertCount(2, $response->json('comment.images'));
        $this->assertSame('หน้าจอ.png', $response->json('comment.images.0.name'));

        // ห้ามส่ง path จริงออกไปที่ JSON ไม่ว่ากรณีใด
        $attachment = WorkOrderUpdateAttachment::firstOrFail();
        $this->assertStringNotContainsString($attachment->file_path, $response->getContent());
        $this->assertSame(
            route('media.comment-attachments.show', $attachment),
            $response->json('comment.images.0.url')
        );

        Storage::disk('local')->assertExists($attachment->file_path);
    }

    /**
     * ภาพหน้าจอเปล่า ๆ เป็นการสื่อสารที่สมบูรณ์ในตัวเอง ไม่ควรบังคับให้พิมพ์อะไรกำกับ
     */
    public function test_an_image_only_comment_is_allowed_but_an_empty_one_is_not(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $task), ['images' => [$this->png('เฉย.png')]])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $task), ['message' => ''])
            ->assertStatus(422);
    }

    public function test_non_images_and_oversized_batches_are_rejected(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $task), [
                'message' => 'ลองแนบเอกสาร',
                'images' => [UploadedFile::fake()->create('สเปค.pdf', 8, 'application/pdf')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $task), [
                'message' => 'แนบเยอะเกิน',
                'images' => array_map(fn ($i) => $this->png("รูป{$i}.png"), range(1, 5)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        $this->assertSame(0, WorkOrderUpdateAttachment::count());
    }

    public function test_a_viewer_cannot_attach_anything(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $this->actingAs($this->user('viewer'))
            ->postJson(route('tasks.comments.store', $task), [
                'message' => 'blocked',
                'images' => [$this->png('x.png')],
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkOrderUpdateAttachment::count());
    }

    /**
     * รูปต้องผ่านการตรวจสิทธิ์ทุกครั้ง URL ที่หลุดออกไปจึงไม่ใช่กุญแจในตัวมันเอง
     */
    public function test_only_people_who_can_read_the_thread_can_open_the_image(): void
    {
        $department = Department::create(['department_name' => 'ฝ่ายไอที']);
        $owner = $this->user();
        $owner->forceFill(['department_id' => $department->id])->save();
        $task = $this->task($owner);

        $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), [
            'images' => [$this->png('ลับ.png')],
        ])->assertCreated();

        $url = route('media.comment-attachments.show', WorkOrderUpdateAttachment::firstOrFail());

        $this->actingAs($owner)->get($url)->assertOk();

        // หัวหน้าแผนกปลายทางอ่านบทสนทนาได้ จึงต้องเห็นรูปประกอบด้วย
        $head = User::factory()->create([
            'role' => 'user',
            'is_department_head' => true,
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->actingAs($head)->get($url)->assertOk();

        // คนนอกงานและ viewer เปิดไม่ได้
        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($this->user('viewer'))->get($url)->assertForbidden();
    }

    /**
     * ไฟล์ถูกเก็บนอก transaction ถ้าการบันทึกล้ม ไฟล์ต้องไม่ค้างเป็นขยะบนดิสก์
     */
    public function test_orphan_files_are_removed_when_the_comment_cannot_be_saved(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $this->mock(TaskCommentService::class, function ($mock) {
            $mock->shouldReceive('post')->andThrow(new \RuntimeException('บันทึกไม่สำเร็จ'));
        });

        try {
            $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), [
                'message' => 'จะล้ม',
                'images' => [$this->png('กำพร้า.png')],
            ]);
        } catch (\Throwable) {
            // ตั้งใจให้โยน สิ่งที่ตรวจคือสถานะของดิสก์หลังจากนั้น
        }

        $this->assertSame(0, WorkOrderUpdateAttachment::count());
        $this->assertEmpty(
            Storage::disk('local')->allFiles('comment-attachments/'.$task->job_id),
            'ไฟล์ที่เก็บไว้แล้วต้องถูกลบเมื่อบันทึกไม่สำเร็จ'
        );
    }

    /**
     * ลบความคิดเห็นแล้วไฟล์ต้องกู้คืนได้เหมือนไฟล์แนบทุกชนิดในระบบ
     */
    public function test_comment_images_use_the_shared_soft_delete_behaviour(): void
    {
        $owner = $this->user();
        $task = $this->task($owner);

        $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), [
            'images' => [$this->png('เก็บไว้.png')],
        ])->assertCreated();

        $attachment = WorkOrderUpdateAttachment::firstOrFail();
        $path = $attachment->file_path;

        $attachment->delete();
        $this->assertSoftDeleted('work_order_update_attachments', ['id' => $attachment->id]);
        $this->assertNotNull(ProtectedMedia::attachmentAbsolutePath($path));

        $attachment->forceDelete();
        $this->assertNull(ProtectedMedia::attachmentAbsolutePath($path));
    }

    private function png(string $name): UploadedFile
    {
        // UploadedFile::fake()->image() ต้องใช้ส่วนขยาย GD ซึ่งเครื่องนี้ไม่ได้ติดตั้ง
        // จึงประกอบ PNG ขนาด 1x1 ขึ้นเองเพื่อให้ getMimeType() อ่านจากเนื้อไฟล์ได้จริง
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $binary);

        return new UploadedFile($path, $name, 'image/png', null, true);
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
            'department_id' => $owner->department_id,
            'job_topic' => 'งานที่มีการคุยกัน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
