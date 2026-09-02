<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: modal แก้ไขงานเคยเรียก window.location.reload() หลังแนบไฟล์สำเร็จ
 * ทำให้ modal ปิดหายไปทันที ผู้ใช้จึงไม่เห็นว่าไฟล์ถูกแนบแล้วและต้องกดเปิดใหม่เอง
 *
 * ทางแก้คือให้ endpoint ส่งรายการไฟล์ล่าสุดกลับมาด้วย หน้าจอจะได้อัปเดตในที่เดิม
 * ชุดทดสอบนี้คุม contract นั้นไว้ ถ้า payload หายไปเมื่อไหร่ UI จะกลับไปพังแบบเดิม
 */
class TaskAttachmentResponseTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_upload_returns_the_current_file_list_so_the_modal_can_stay_open(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        $response = $this->actingAs($member)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->pngUpload('หลักฐาน.png')],
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'หลักฐาน.png');

        // ต้องมีทั้งลิงก์เปิดไฟล์และลิงก์ลบ ไม่งั้นรายการที่วาดใหม่จะกดลบไม่ได้
        $this->assertNotEmpty($response->json('files.0.url'));
        $this->assertNotEmpty($response->json('files.0.delete_url'));
    }

    public function test_delete_returns_the_remaining_files_instead_of_forcing_a_reload(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        $this->actingAs($member)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->pngUpload('เก็บไว้.png'), $this->pngUpload('ลบทิ้ง.png')],
            ])->assertOk();

        $removed = $task->images()->where('original_name', 'ลบทิ้ง.png')->firstOrFail();

        $this->actingAs($member)
            ->deleteJson(route('tasks.attachments.destroy', [$task->job_id, $removed]))
            ->assertOk()
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'เก็บไว้.png');
    }

    private function pngUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode(self::ONE_PIXEL_PNG));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** @return array{0: User, 1: WorkOrder} */
    private function scenario(): array
    {
        $department = Department::create(['department_name' => 'Attachments']);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id, 'is_active' => true]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $department->id,
            'job_topic' => 'งานทดสอบไฟล์แนบ',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $task];
    }
}
