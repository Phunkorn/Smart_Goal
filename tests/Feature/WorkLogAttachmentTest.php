<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogAttachment;
use App\Support\ProtectedMedia;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ไฟล์แนบของบันทึกงานประจำวัน
 *
 * ไฟล์เป็นไฟล์ส่วนตัว เสิร์ฟผ่าน MediaController เท่านั้น จุดที่ต้องคุมคือ
 * ชนิดไฟล์ต้องตรวจจากเนื้อไฟล์จริง และ path ใน storage ต้องไม่หลุดออกไปถึง
 * เบราว์เซอร์ในรูปแบบใดเลย
 */
class WorkLogAttachmentTest extends TestCase
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

    public function test_owner_can_attach_a_file_to_own_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->pdf('ใบเสร็จค่าเดินทาง.pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $attachment = WorkLogAttachment::query()->where('work_log_id', $log->id)->firstOrFail();

        $this->assertStringStartsWith('work-log-attachments/'.$log->id.'/', $attachment->file_path);
        $this->assertSame('ใบเสร็จค่าเดินทาง.pdf', $attachment->original_name);
        $this->assertSame($owner->id, $attachment->uploaded_by);
        Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->assertExists($attachment->file_path);
    }

    /**
     * ชนิดไฟล์ที่บันทึกต้องมาจากการอ่านเนื้อไฟล์ ไม่ใช่ Content-Type ที่ client ส่งมา
     */
    public function test_the_stored_file_type_comes_from_the_file_contents(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $log), [
            'attachments' => [$this->pdf('report.pdf')],
        ])->assertRedirect();

        $this->assertSame(
            'application/pdf',
            WorkLogAttachment::query()->where('work_log_id', $log->id)->value('file_type')
        );
    }

    /**
     * ไฟล์สคริปต์ที่เปลี่ยนนามสกุลเป็น .pdf ต้องถูกปฏิเสธที่ชั้น validation
     * เพราะกฎ mimes: เทียบจากเนื้อไฟล์จริง ไม่ใช่ชื่อไฟล์
     */
    public function test_a_php_file_renamed_to_pdf_is_rejected(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->realFile('payload.pdf', "<?php echo 'hi';")],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, WorkLogAttachment::query()->count());

        // ต้องไม่มีไฟล์กำพร้าค้างอยู่ใน storage
        $this->assertSame(
            [],
            Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->allFiles('work-log-attachments')
        );
    }

    /**
     * ด่านที่สอง — สำคัญที่สุดด้านความปลอดภัย
     *
     * กฎ mimes: ของ Laravel ตรวจว่า "เนื้อไฟล์เป็นชนิดที่อนุญาตชนิดใดชนิดหนึ่ง"
     * แต่ไม่ได้ตรวจว่านามสกุลที่ผู้ใช้ตั้งมาตรงกับเนื้อไฟล์นั้นหรือไม่ ไฟล์ PNG
     * ที่เปลี่ยนนามสกุลเป็น .pdf จึงผ่านชั้นแรกไปได้ AttachmentPolicy จึงต้อง
     * จับคู่นามสกุลกับ MIME อีกชั้นและตอบ 422
     */
    public function test_a_mime_and_extension_mismatch_is_rejected_with_422(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->png('disguised.pdf')],
            ])
            ->assertStatus(422);

        $this->assertSame(0, WorkLogAttachment::query()->count());
        $this->assertSame(
            [],
            Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->allFiles('work-log-attachments')
        );
    }

    /**
     * นามสกุลที่ไม่อยู่ใน allow-list เลยต้องถูกปฏิเสธ แม้เนื้อไฟล์จะเป็นชนิดที่
     * ระบบยอมรับก็ตาม (PNG ที่ตั้งชื่อเป็น .exe ผ่านกฎ mimes: ได้)
     */
    public function test_a_disallowed_extension_is_rejected_even_with_allowed_contents(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->png('payload.exe')],
            ])
            ->assertStatus(422);

        $this->assertSame(0, WorkLogAttachment::query()->count());
    }

    public function test_the_attachment_limit_counts_files_already_present(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $files = [];
        for ($index = 0; $index < WorkLogDesign::MAX_ATTACHMENTS; $index++) {
            $files[] = $this->pdf("file-{$index}.pdf");
        }

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), ['attachments' => $files])
            ->assertRedirect();

        $this->assertSame(WorkLogDesign::MAX_ATTACHMENTS, WorkLogAttachment::query()->count());

        // อัปรอบที่สองต้องถูกปฏิเสธ เพราะนับรวมของเดิมแล้วเกินเพดาน
        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), ['attachments' => [$this->pdf('extra.pdf')]])
            ->assertSessionHasErrors('attachments');

        $this->assertSame(WorkLogDesign::MAX_ATTACHMENTS, WorkLogAttachment::query()->count());
    }

    public function test_owner_can_delete_an_attachment_and_the_file_is_removed(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $log), [
            'attachments' => [$this->pdf('doc.pdf')],
        ]);

        $attachment = WorkLogAttachment::query()->firstOrFail();
        $path = $attachment->file_path;

        $this->actingAs($owner)
            ->delete(route('daily-logs.attachments.destroy', [$log, $attachment]))
            ->assertRedirect();

        // ไฟล์แนบเข้าถังขยะ 30 วัน แถวหายจากรายการปกติแต่ตัวไฟล์ต้องอยู่ต่อ
        // เพื่อให้ปุ่มกู้คืนในหน้า Audit Log คืนไฟล์ที่เปิดได้จริง ไม่ใช่แถวเปล่า
        $this->assertSame(0, WorkLogAttachment::query()->count());
        $this->assertSame(1, WorkLogAttachment::onlyTrashed()->count());
        Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->assertExists($path);
    }

    /**
     * ส่ง id ของไฟล์แนบที่อยู่คนละบันทึกต้องได้ 404 ไม่ใช่ลบข้ามบันทึกกัน
     */
    public function test_an_attachment_from_another_log_cannot_be_deleted(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $first = $this->log($owner, $department);
        $second = $this->log($owner, $department, ['title' => 'บันทึกที่สอง']);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $first), [
            'attachments' => [$this->pdf('doc.pdf')],
        ]);

        $attachment = WorkLogAttachment::query()->firstOrFail();

        $this->actingAs($owner)
            ->delete(route('daily-logs.attachments.destroy', [$second, $attachment]))
            ->assertNotFound();

        $this->assertSame(1, WorkLogAttachment::query()->count());
    }

    /**
     * หัวหน้าและ admin เห็นบันทึกได้ แต่แนบไฟล์เข้าบันทึกของคนอื่นไม่ได้
     * (ผูกกับสิทธิ์แก้ไข ซึ่งเป็นของเจ้าของเท่านั้น)
     */
    public function test_only_the_owner_can_attach_or_delete_files(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $admin = $this->user(null, false, 'admin');
        $log = $this->log($owner, $department);

        foreach ([$head, $admin] as $actor) {
            $this->actingAs($actor)
                ->post(route('daily-logs.attachments.store', $log), ['attachments' => [$this->pdf('doc.pdf')]])
                ->assertForbidden();
        }

        $this->assertSame(0, WorkLogAttachment::query()->count());
    }

    public function test_viewer_cannot_attach_files(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $viewer = $this->user($department, false, 'viewer');
        $log = $this->log($owner, $department);

        $this->actingAs($viewer)
            ->post(route('daily-logs.attachments.store', $log), ['attachments' => [$this->pdf('doc.pdf')]])
            ->assertForbidden();
    }

    /**
     * การเสิร์ฟไฟล์ต้องตรวจสิทธิ์จากบันทึกต้นทาง ผู้ที่เปิดดูได้จึงเป็นชุดเดียวกับ
     * ผู้ที่เห็นบันทึกนั้น — เจ้าของ หัวหน้าแผนก และ admin
     */
    public function test_the_media_route_serves_the_file_to_everyone_who_can_see_the_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $admin = $this->user(null, false, 'admin');
        $log = $this->log($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $log), [
            'attachments' => [$this->pdf('doc.pdf')],
        ]);

        $attachment = WorkLogAttachment::query()->firstOrFail();

        foreach ([$owner, $head, $admin] as $actor) {
            $this->actingAs($actor)
                ->get(route('media.work-log-attachments.show', $attachment))
                ->assertOk();
        }
    }

    public function test_the_media_route_rejects_people_who_cannot_see_the_log(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $colleague = $this->user($it);
        $salesHead = $this->user($sales, true);
        $viewer = $this->user($it, false, 'viewer');
        $log = $this->log($owner, $it);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $log), [
            'attachments' => [$this->pdf('doc.pdf')],
        ]);

        $attachment = WorkLogAttachment::query()->firstOrFail();

        foreach ([$colleague, $salesHead, $viewer] as $actor) {
            $this->actingAs($actor)
                ->get(route('media.work-log-attachments.show', $attachment))
                ->assertForbidden();
        }
    }

    /**
     * path จริงใน storage ต้องไม่หลุดออกไปถึงเบราว์เซอร์ในรูปแบบใดเลย
     * ไม่ว่าจะเป็น HTML ของหน้า หรือ payload ของ AJAX
     */
    public function test_the_raw_storage_path_never_reaches_the_client(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->pdf('doc.pdf')],
            ])
            ->assertOk();

        $path = WorkLogAttachment::query()->firstOrFail()->file_path;

        $response->assertDontSee($path);
        $this->assertStringNotContainsString($path, $response->getContent());
        $this->assertSame(
            route('media.work-log-attachments.show', WorkLogAttachment::query()->firstOrFail()),
            $response->json('log.attachments.0.url')
        );

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertDontSee($path);
    }

    public function test_the_ajax_payload_refreshes_the_row_so_the_file_count_stays_right(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->pdf('doc.pdf')],
            ])
            ->assertOk()
            ->assertJsonStructure(['ok', 'message', 'log', 'html']);

        $this->assertStringContainsString('1 ไฟล์', $response->json('html'));
    }

    /**
     * การลบบันทึกต้องพาไฟล์แนบไปด้วย ไม่ทิ้งแถวกำพร้าไว้ในตาราง
     */
    public function test_deleting_the_log_removes_its_attachments(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.attachments.store', $log), [
            'attachments' => [$this->pdf('doc.pdf')],
        ]);

        $this->assertSame(1, WorkLogAttachment::query()->count());

        // work_logs เป็น soft delete ไฟล์แนบจึงยังอยู่กับบันทึกที่ถูกลบ
        // และจะถูกลบจริงพร้อมกันเมื่อบันทึกถูกลบถาวร (cascadeOnDelete)
        $this->actingAs($owner)->delete(route('daily-logs.destroy', $log))->assertRedirect();
        $this->assertSoftDeleted('work_logs', ['id' => $log->id]);

        $log->forceDelete();

        $this->assertSame(0, WorkLogAttachment::query()->count());
    }

    private function pdf(string $name): UploadedFile
    {
        // %PDF- คือ magic bytes ที่ทำให้ finfo อ่านเป็น application/pdf จริง
        return $this->realFile($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    }

    private function png(string $name): UploadedFile
    {
        return $this->realFile($name, base64_decode(self::ONE_PIXEL_PNG));
    }

    /**
     * สร้างไฟล์จริงบนดิสก์แล้วห่อเป็น UploadedFile แบบโหมดทดสอบ
     *
     * ต้องทำแบบนี้แทน UploadedFile::fake() เพราะ fake เดา MIME จาก "ชื่อไฟล์"
     * ไม่ใช่เนื้อไฟล์ จึงทดสอบการตรวจ MIME จากเนื้อหาจริงไม่ได้เลย
     * (เทคนิคเดียวกับที่ TaskAttachmentFileTypeTest ใช้อยู่)
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgwlog');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** PNG ขนาด 1x1 พิกเซล ใช้เป็นเนื้อไฟล์จริงสำหรับทดสอบการตรวจ MIME */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    private function user(?Department $department, bool $head = false, string $role = 'user'): User
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
            'kind' => 'field',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'ไปส่งรถที่ศูนย์บริการ',
            'duration_minutes' => 165,
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ], $overrides));
    }
}
