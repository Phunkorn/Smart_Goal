<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * การ์ด "ไฟล์แนบ" ใน Task Workspace เคยมีแต่ลิงก์เปิดดูไฟล์ทีละไฟล์
 * ไม่มีปุ่มดาวน์โหลด และเลือกหลายรายการพร้อมกันไม่ได้
 *
 * ฝั่ง server ที่งานนี้ต้องมีคือ
 *   1. ลิงก์ที่บังคับให้เบราว์เซอร์บันทึกไฟล์ด้วย "ชื่อเดิมของผู้ใช้" ไม่ใช่ชื่อ hash บนดิสก์
 *   2. ส่ง download_url มาใน payload ทุกทางที่หน้าจอใช้วาดรายการไฟล์
 *   3. ตรวจสิทธิ์ชุดเดียวกับการเปิดดู — ดาวน์โหลดได้ก็ต้องเปิดดูได้ และกลับกัน
 */
class TaskAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_download_forces_an_attachment_disposition_with_the_original_name(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();
        $attachment = $this->upload($member, $task, 'สรุปงาน ไตรมาส1.png');

        $response = $this->actingAs($member)
            ->get(route('media.task-attachments.show', [$attachment, 'download' => 1]));

        $response->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        // ชื่อไทยต้องออกเป็น filename*= แบบ UTF-8 ไม่ใช่หายไปเหลือแต่ fallback
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('สรุปงาน ไตรมาส1.png'), $disposition);

        // ชื่อบนดิสก์ถูก hash ตอนอัปโหลด ถ้าหลุดออกไปผู้ใช้จะได้ไฟล์ที่เปิดไม่ออก
        $this->assertStringNotContainsString(basename((string) $attachment->file_path), $disposition);
    }

    public function test_viewing_without_the_flag_still_opens_inline(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();
        $attachment = $this->upload($member, $task, 'หลักฐาน.png');

        $response = $this->actingAs($member)
            ->get(route('media.task-attachments.show', $attachment));

        $response->assertOk();
        // response()->file() ไม่ตั้ง Content-Disposition เลย เบราว์เซอร์จึงเปิดดูในแท็บตามเดิม
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * สิทธิ์ดาวน์โหลดคือสิทธิ์เปิดดู ไม่ใช่กติกาชุดที่สอง
     * ใช้ route เดียวกันจึงรับประกันได้ว่าสองเรื่องนี้เพี้ยนออกจากกันไม่ได้
     */
    public function test_an_outsider_cannot_download_an_attachment_it_cannot_view(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();
        $attachment = $this->upload($member, $task, 'ลับ.png');

        $outsider = User::factory()->create([
            'role' => 'user',
            'department_id' => Department::create(['department_name' => 'แผนกอื่น'])->id,
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->get(route('media.task-attachments.show', [$attachment, 'download' => 1]))
            ->assertForbidden();
    }

    public function test_guests_cannot_download_an_attachment(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();
        $attachment = $this->upload($member, $task, 'หลักฐาน.png');

        // actingAs ของขั้นเตรียมข้อมูลยังค้างอยู่ใน guard ต้องออกจากระบบก่อนจึงจะทดสอบผู้ไม่ล็อกอินได้จริง
        Auth::logout();

        $this->get(route('media.task-attachments.show', [$attachment, 'download' => 1]))
            ->assertRedirect(route('login'));
    }

    /**
     * หน้าจอวาดรายการไฟล์จาก payload นี้ตรง ๆ ถ้า download_url หาย
     * ปุ่มดาวน์โหลดจะตกไปใช้ลิงก์เปิดดู แล้วผู้ใช้จะได้ไฟล์ชื่อ hash
     */
    public function test_the_upload_payload_carries_a_download_link_for_every_file(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        $response = $this->actingAs($member)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->pngUpload('หลักฐาน.png')],
            ]);

        $response->assertOk()->assertJsonCount(1, 'files');
        $this->assertNotEmpty($response->json('files.0.download_url'));
        $this->assertStringContainsString('download=1', $response->json('files.0.download_url'));
    }

    public function test_the_workspace_payload_carries_a_download_link_for_every_file(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();
        $this->upload($member, $task, 'สรุปงาน.png');

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('download_url', false)
            ->assertSee('download=1', false);
    }

    private function upload(User $member, WorkOrder $task, string $name): JobImage
    {
        $this->actingAs($member)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->pngUpload($name)],
            ])->assertOk();

        return $task->images()->where('original_name', $name)->firstOrFail();
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
        $department = Department::create(['department_name' => 'Attachment downloads']);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id, 'is_active' => true]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $department->id,
            'job_topic' => 'งานทดสอบดาวน์โหลดไฟล์แนบ',
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
