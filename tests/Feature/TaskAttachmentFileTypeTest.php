<?php

namespace Tests\Feature;

use App\Http\Controllers\TaskAttachmentController;
use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderListAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression: job_images.file_type เคยเป็น varchar(50) แต่แอปเก็บ MIME type เต็ม
 * ของ Office 2007+ ซึ่งยาว 65-73 ตัวอักษร ทำให้แนบไฟล์ .docx .xlsx .pptx
 * ล้มด้วย SQLSTATE[22001] Data too long for column 'file_type' บน MySQL ทุกครั้ง
 *
 * ชุดทดสอบนี้คุมทั้งเส้นทางบันทึกจริง และคุมว่า allow-list กับความจุคอลัมน์ต้องไม่หลุดจากกันอีก
 */
class TaskAttachmentFileTypeTest extends TestCase
{
    use RefreshDatabase;

    /** PNG ขนาด 1x1 ที่ถูกต้องตามสเปก ใช้ให้ fileinfo ตรวจได้ว่าเป็น image/png จริง */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private const OFFICE_UPLOADS = [
        ['report.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ['brief.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    public function test_office_attachments_keep_their_full_mime_type(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        foreach (self::OFFICE_UPLOADS as [$name, $mime]) {
            $this->actingAs($member)
                ->post(route('tasks.attachments.store', $task->job_id), [
                    'completion_attachments' => [UploadedFile::fake()->create($name, 40, $mime)],
                ], ['Accept' => 'application/json'])
                ->assertSuccessful();

            $stored = JobImage::where('original_name', $name)->firstOrFail();

            $this->assertSame($mime, $stored->file_type, 'MIME ต้องถูกเก็บครบ ไม่ถูกตัดปลาย');
            $this->assertGreaterThan(50, strlen($mime), 'ค่านี้คือค่าที่เคยทำให้ varchar(50) พัง');
        }

        $this->assertSame(3, JobImage::where('job_id', $task->job_id)->count());
    }

    public function test_admin_uploading_to_a_member_task_hits_the_same_path(): void
    {
        Storage::fake('local');
        [, $task, $admin] = $this->scenario();
        [$name, $mime] = self::OFFICE_UPLOADS[0];

        $this->actingAs($admin)
            ->post(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [UploadedFile::fake()->create($name, 40, $mime)],
            ], ['Accept' => 'application/json'])
            ->assertSuccessful();

        $this->assertSame($mime, JobImage::where('original_name', $name)->firstOrFail()->file_type);
    }

    /**
     * getClientMimeType() คือ Content-Type ที่ client ส่งมา ปลอมได้และยาวไม่จำกัด
     * การขยายคอลัมน์เป็น 255 อย่างเดียวจึงยังไม่ปิดช่อง เพราะผู้โจมตีส่งค่ายาวกว่านั้นได้
     * ระบบต้องเก็บ MIME ที่ตรวจจากเนื้อไฟล์จริงเท่านั้น
     */
    public function test_forged_client_content_type_is_never_stored(): void
    {
        Storage::fake('local');
        [$member, $task] = $this->scenario();

        $forged = str_repeat('a', 300);
        $this->assertGreaterThan(JobImage::FILE_TYPE_MAX_LENGTH, strlen($forged));

        $this->actingAs($member)
            ->post(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->fileWithForgedClientMime('evidence.png', $forged)],
            ], ['Accept' => 'application/json'])
            ->assertSuccessful();

        $stored = JobImage::where('original_name', 'evidence.png')->firstOrFail();

        $this->assertSame('image/png', $stored->file_type, 'ต้องเก็บ MIME ที่ตรวจจากเนื้อไฟล์ ไม่ใช่ค่าที่ client ส่งมา');
        $this->assertNotSame($forged, $stored->file_type);
        $this->assertLessThanOrEqual(JobImage::FILE_TYPE_MAX_LENGTH, strlen((string) $stored->file_type));
    }

    /** ไฟล์แนบของโปรเจกต์เขียนคนละตารางแต่มาจากช่องโหว่เดียวกัน จึงต้องคุมด้วย */
    public function test_forged_client_content_type_is_never_stored_on_project_attachments(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$member] = $this->scenario();

        $forged = str_repeat('b', 300);

        $this->actingAs($member)
            ->post(route('mytasks.create'), [
                'project_name' => 'โปรเจกต์ทดสอบไฟล์แนบ',
                'job_topic' => 'งานแรก',
                'user_id' => $member->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'project_priority' => 2,
                'attachments' => [$this->fileWithForgedClientMime('cover.png', $forged)],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $stored = WorkOrderListAttachment::where('original_name', 'cover.png')->firstOrFail();

        $this->assertSame('image/png', $stored->file_type);
        $this->assertNotSame($forged, $stored->file_type);
    }

    /**
     * ไฟล์ PNG จริง แต่ประกาศ Content-Type ปลอมมากับคำขอ
     *
     * เขียนไฟล์เองแทนการใช้ UploadedFile::fake() เพราะ object ของ fake ถือ handle ของ
     * temp file ไว้ พอถูก garbage collect ไฟล์จะหายก่อนที่ request จะอ่าน
     */
    private function fileWithForgedClientMime(string $name, string $forgedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgpng');
        file_put_contents($path, base64_decode(self::ONE_PIXEL_PNG));
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, $forgedMime, null, true);
    }

    public function test_every_allowed_mime_fits_the_file_type_column(): void
    {
        $allowed = (new ReflectionClass(TaskAttachmentController::class))
            ->getReflectionConstant('ALLOWED_ATTACHMENT_MIMES')
            ->getValue();

        $this->assertNotEmpty($allowed);

        foreach ($allowed as $mime) {
            $this->assertLessThanOrEqual(
                JobImage::FILE_TYPE_MAX_LENGTH,
                strlen($mime),
                'MIME "'.$mime.'" ยาวเกินความจุคอลัมน์ file_type — ต้องขยายคอลัมน์ก่อนเพิ่มชนิดไฟล์นี้'
            );
        }
    }

    public function test_file_type_column_is_wide_enough_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('ตรวจความกว้างคอลัมน์ได้เฉพาะบน MySQL — SQLite ไม่บังคับความยาว varchar');
        }

        $length = DB::selectOne(
            'select CHARACTER_MAXIMUM_LENGTH as len from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['job_images', 'file_type']
        )?->len;

        $this->assertGreaterThanOrEqual(JobImage::FILE_TYPE_MAX_LENGTH, (int) $length);
    }

    /**
     * @return array{0: User, 1: WorkOrder, 2: User}
     */
    private function scenario(): array
    {
        $department = Department::create(['department_name' => 'Attachments']);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $department->id, 'is_active' => true]);

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

        return [$member, $task, $admin];
    }
}
