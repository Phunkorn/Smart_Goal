<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Support\ProtectedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * การเก็บไฟล์แนบของโปรเจกต์
 *
 * มีอยู่เพื่อคุมเส้นทางนี้ด้วยไฟล์ที่ไม่ต้องพึ่งส่วนขยาย GD ของ PHP
 * เทสต์เดิมของฟีเจอร์นี้ (MyTasksProjectManagementTest และ AdminWorkBoardTest)
 * ใช้ UploadedFile::fake()->image() ซึ่งต้องมี GD จึงข้ามไปเงียบ ๆ บนเครื่องที่
 * ไม่ได้เปิดส่วนขยายนั้น ทำให้การแก้ไขวิธีเก็บไฟล์ไม่มีอะไรมาจับความผิดพลาด
 *
 * เขียนไว้ตอนย้าย storeListAttachments() ไปใช้ตัวช่วยร่วมของ
 * ValidatesAttachments::collectStoredAttachments() แทนสำเนาลูปที่คัดลอกไว้เอง
 */
class ProjectAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ProtectedMedia::ATTACHMENT_DISK);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function test_owner_can_upload_a_project_attachment_and_the_mime_comes_from_the_contents(): void
    {
        [$owner, $project] = $this->scenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->pdf('ขอบเขตงาน.pdf')],
            ])
            ->assertOk();

        $attachment = WorkOrderListAttachment::query()->where('work_order_list_id', $project->id)->firstOrFail();

        $this->assertStringStartsWith('project-attachments/'.$project->id.'/', $attachment->file_path);
        $this->assertSame('ขอบเขตงาน.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->file_type);
        $this->assertSame($owner->id, $attachment->uploaded_by);
        Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->assertExists($attachment->file_path);
    }

    public function test_multiple_files_are_all_stored(): void
    {
        [$owner, $project] = $this->scenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->pdf('หนึ่ง.pdf'), $this->pdf('สอง.pdf')],
            ])
            ->assertOk();

        $this->assertSame(2, WorkOrderListAttachment::query()->count());
        $this->assertSame(
            ['หนึ่ง.pdf', 'สอง.pdf'],
            WorkOrderListAttachment::query()->orderBy('id')->pluck('original_name')->all()
        );
    }

    /**
     * นามสกุลกับ MIME ต้องเข้าคู่กัน ไม่ใช่ตรวจแยกกันสองรายการ
     * PNG ที่เปลี่ยนนามสกุลเป็น .pdf ผ่านกฎ mimes: ของ Laravel ได้
     */
    public function test_a_mime_and_extension_mismatch_is_rejected_and_leaves_no_file(): void
    {
        [$owner, $project] = $this->scenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->png('disguised.pdf')],
            ])
            ->assertStatus(422);

        $this->assertSame(0, WorkOrderListAttachment::query()->count());
        $this->assertSame(
            [],
            Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->allFiles('project-attachments')
        );
    }

    public function test_deleting_an_attachment_removes_the_stored_file(): void
    {
        [$owner, $project] = $this->scenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->pdf('doc.pdf')],
            ])
            ->assertOk();

        $attachment = WorkOrderListAttachment::query()->firstOrFail();
        $path = $attachment->file_path;

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->deleteJson(route('mytasks.lists.attachments.destroy', [$project, $attachment]))
            ->assertOk();

        // ไฟล์แนบเข้าถังขยะ 30 วัน แถวหายจากรายการปกติแต่ตัวไฟล์ต้องอยู่ต่อ
        // เพื่อให้ปุ่มกู้คืนในหน้า Audit Log คืนไฟล์ที่เปิดได้จริง ไม่ใช่แถวเปล่า
        $this->assertSame(0, WorkOrderListAttachment::query()->count());
        $this->assertSame(1, WorkOrderListAttachment::onlyTrashed()->count());
        Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->assertExists($path);
    }

    /**
     * @return array{0: User, 1: WorkOrderList}
     */
    private function scenario(): array
    {
        $department = Department::create(['department_name' => 'Projects']);
        $owner = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        return [$owner, WorkOrderList::create(['user_id' => $owner->id, 'name' => 'ระบบเครือข่ายสำนักงานใหม่'])];
    }

    private function pdf(string $name): UploadedFile
    {
        return $this->realFile($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    }

    private function png(string $name): UploadedFile
    {
        return $this->realFile($name, base64_decode(self::ONE_PIXEL_PNG));
    }

    /**
     * ไฟล์จริงบนดิสก์ ไม่ใช่ UploadedFile::fake() ที่เดา MIME จากชื่อไฟล์
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgproj');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';
}
