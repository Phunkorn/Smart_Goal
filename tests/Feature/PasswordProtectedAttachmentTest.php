<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AttachmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: ไฟล์ Excel ที่ตั้งรหัสผ่านเปิดไฟล์ (Encrypt with Password) แนบไม่ได้
 * ขึ้นข้อความ "completion_attachments.0 ต้องเป็นไฟล์ชนิด: ..." ทั้งที่นามสกุลเป็น .xlsx
 *
 * ไฟล์แบบนี้เป็นกล่อง OLE2 ไม่ใช่ ZIP fileinfo จึงรายงาน application/encrypted
 * ซึ่ง mimes: ของ Laravel เดานามสกุลไม่ได้ ไฟล์ทดสอบสร้างจาก Excel จริง (รหัส smartgoal-test)
 */
class PasswordProtectedAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/attachments/password-protected.xlsx';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_fixture_is_detected_as_an_encrypted_office_file(): void
    {
        $this->assertSame(AttachmentPolicy::PASSWORD_PROTECTED_MIME, $this->upload('รายงาน.xlsx')->getMimeType());
    }

    public function test_password_protected_excel_passes_the_policy(): void
    {
        $file = $this->upload('รายงาน.xlsx');

        $this->assertTrue(AttachmentPolicy::isPasswordProtectedSpreadsheet($file));
        $this->assertTrue(AttachmentPolicy::contentTypeIsAllowed($file));
        $this->assertNull(AttachmentPolicy::rejectionReason($file));
        $this->assertSame('xlsx', AttachmentPolicy::storedExtensionOverride($file));
    }

    /** เปิดให้เฉพาะ Excel — ไฟล์เข้ารหัสที่ตั้งชื่อเป็นนามสกุลอื่นยังต้องตก */
    public function test_encrypted_container_under_another_extension_is_rejected(): void
    {
        foreach (['ปลอม.docx', 'ปลอม.pdf', 'ปลอม.zip'] as $name) {
            $file = $this->upload($name);

            $this->assertFalse(AttachmentPolicy::isPasswordProtectedSpreadsheet($file), $name);
            $this->assertFalse(AttachmentPolicy::contentTypeIsAllowed($file), $name);
            $this->assertStringContainsString('ไม่ตรงกับนามสกุล', (string) AttachmentPolicy::rejectionReason($file), $name);
        }
    }

    /** MIME ที่อ้างว่าเข้ารหัสแต่เนื้อไฟล์ไม่ใช่ OLE2 ต้องไม่ผ่าน */
    public function test_claimed_encrypted_mime_without_ole2_signature_is_rejected(): void
    {
        $fake = UploadedFile::fake()->create('ปลอม.xlsx', 10, AttachmentPolicy::PASSWORD_PROTECTED_MIME);

        $this->assertFalse(AttachmentPolicy::isPasswordProtectedSpreadsheet($fake));
        $this->assertNotNull(AttachmentPolicy::rejectionReason($fake));
    }

    public function test_member_can_attach_a_password_protected_excel_file(): void
    {
        [$member, $task] = $this->scenario();

        $this->assertAttachesWithLock($member, $task);
    }

    public function test_admin_attaching_to_a_member_task_uses_the_same_path(): void
    {
        [, $task, $admin] = $this->scenario();

        $this->assertAttachesWithLock($admin, $task);
    }

    public function test_regular_excel_file_is_not_marked_as_password_protected(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        $this->actingAs($member)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [UploadedFile::fake()->create('ปกติ.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
            ])
            ->assertOk()
            ->assertJsonPath('files.0.password_protected', false);
    }

    private function assertAttachesWithLock(User $actor, WorkOrder $task): void
    {
        Storage::fake('local');

        $this->actingAs($actor)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->upload('RSA_04_11_2026.xlsx')],
            ])
            ->assertOk()
            ->assertJsonPath('files.0.name', 'RSA_04_11_2026.xlsx')
            ->assertJsonPath('files.0.password_protected', true);

        $stored = JobImage::where('original_name', 'RSA_04_11_2026.xlsx')->firstOrFail();

        $this->assertSame(AttachmentPolicy::PASSWORD_PROTECTED_MIME, $stored->file_type);
        // Laravel เดานามสกุลจาก application/encrypted ไม่ได้ ถ้าไม่ต่อเองไฟล์บนดิสก์จะไม่มีนามสกุล
        $this->assertStringEndsWith('.xlsx', $stored->file_path);
        Storage::disk('local')->assertExists($stored->file_path);
        $this->assertSame(
            file_get_contents(self::FIXTURE),
            Storage::disk('local')->get($stored->file_path),
            'ต้องเก็บไฟล์ต้นฉบับครบทุกไบต์ ผู้รับจึงเปิดด้วยรหัสผ่านเดิมได้'
        );
    }

    private function upload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgenc');
        copy(self::FIXTURE, $path);
        $this->temporaryFiles[] = $path;

        // Content-Type จาก client เป็นค่าปลอม เพื่อพิสูจน์ว่าระบบอ่านจากเนื้อไฟล์จริงเท่านั้น
        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return array{0: User, 1: WorkOrder, 2: User}
     */
    private function scenario(): array
    {
        $department = Department::create(['department_name' => 'Encrypted Attachments']);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $department->id, 'is_active' => true]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $department->id,
            'job_topic' => 'งานทดสอบไฟล์ตั้งรหัสผ่าน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $task, $admin];
    }
}
