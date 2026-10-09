<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogAttachment;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Support\ProtectedMedia;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ชื่อไฟล์ที่ผู้ใช้ได้ตอนกดลิงก์ไฟล์แนบ
 *
 * อาการที่พบ: ผู้ใช้แนบไฟล์ Excel ไว้ในงาน แต่ไฟล์ที่ดาวน์โหลดออกมาชื่อ "224"
 * ไม่มีนามสกุล และเปิดด้วย Excel ไม่ได้
 *
 * ต้นเหตุ: response()->file() ไม่ตั้ง Content-Disposition ให้เอง เมื่อไม่มีชื่อไฟล์
 * ติดไปกับ response เบราว์เซอร์จะตั้งชื่อไฟล์จากส่วนท้ายของ URL ซึ่ง route ไฟล์แนบ
 * ทุกเส้นลงท้ายด้วย id ของไฟล์ ผู้ใช้จึงได้ไฟล์ชื่อเป็นเลข id
 *
 * เดิมมีแต่ไฟล์แนบของงาน (JobImage) ที่ส่งชื่อออกไป และส่งเฉพาะเมื่อมี ?download=1
 * ไฟล์แนบของโปรเจกต์ บันทึกงานประจำวัน กระดานไอเดีย และความคิดเห็น ไม่ส่งเลย
 * ทั้งที่หน้าจอลิงก์ไปด้วย target="_blank" ธรรมดา ซึ่งเป็นทางที่ผู้ใช้ใช้จริง
 */
class AttachmentDownloadNameTest extends TestCase
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

    /**
     * ไฟล์แนบของงาน — ทางที่หน้าจอใช้เปิดดูไฟล์ ไม่ใช่ปุ่มดาวน์โหลด
     *
     * ลิงก์ชื่อไฟล์ในการ์ด "ไฟล์แนบ" เปิดด้วย url ธรรมดา เอกสาร Office เปิดในแท็บไม่ได้
     * เบราว์เซอร์จึงบันทึกลงเครื่องให้ และต้องบันทึกด้วยชื่อเดิม
     */
    public function test_viewing_a_task_attachment_still_carries_the_original_name(): void
    {
        [$owner, $task] = $this->taskScenario();

        $this->actingAs($owner)
            ->postJson(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [$this->pdf('สรุปยอดเดือนมีนาคม.pdf')],
            ])->assertOk();

        $attachment = $task->images()->firstOrFail();

        $response = $this->actingAs($owner)
            ->get(route('media.task-attachments.show', $attachment));

        $response->assertOk();
        $this->assertOriginalName($response->headers->get('Content-Disposition'), 'สรุปยอดเดือนมีนาคม.pdf');
        // ยังเป็น inline เพื่อให้รูปและ PDF เปิดดูในแท็บได้เหมือนเดิม
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertHashIsHidden($response->headers->get('Content-Disposition'), $attachment->file_path);
    }

    public function test_project_attachment_carries_the_original_name(): void
    {
        [$owner, $project] = $this->projectScenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->pdf('ขอบเขตงาน ระบบจัดการงาน.pdf')],
            ])->assertOk();

        $attachment = WorkOrderListAttachment::query()
            ->where('work_order_list_id', $project->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)
            ->get(route('media.project-attachments.show', $attachment));

        $response->assertOk();
        $this->assertOriginalName($response->headers->get('Content-Disposition'), 'ขอบเขตงาน ระบบจัดการงาน.pdf');
        $this->assertHashIsHidden($response->headers->get('Content-Disposition'), $attachment->file_path);
    }

    public function test_work_log_attachment_carries_the_original_name(): void
    {
        $department = Department::create(['department_name' => 'ไฟล์แนบบันทึกงาน']);
        $owner = $this->member($department);
        $log = WorkLog::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department->id,
            'kind' => 'field',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'ไปติดตั้งอุปกรณ์',
            'duration_minutes' => 120,
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ]);

        $this->actingAs($owner)
            ->post(route('daily-logs.attachments.store', $log), [
                'attachments' => [$this->pdf('ใบเสร็จค่าเดินทาง.pdf')],
            ])->assertRedirect();

        $attachment = WorkLogAttachment::query()->where('work_log_id', $log->id)->firstOrFail();

        $response = $this->actingAs($owner)
            ->get(route('media.work-log-attachments.show', $attachment));

        $response->assertOk();
        $this->assertOriginalName($response->headers->get('Content-Disposition'), 'ใบเสร็จค่าเดินทาง.pdf');
        $this->assertHashIsHidden($response->headers->get('Content-Disposition'), $attachment->file_path);
    }

    /**
     * ?download=1 ยังต้องบังคับบันทึกไฟล์ ไม่ใช่เปิดดู และต้องใช้ได้กับไฟล์แนบทุกชนิด
     * ไม่ใช่เฉพาะไฟล์แนบของงานเหมือนเดิม
     */
    public function test_the_download_flag_forces_an_attachment_disposition_on_other_attachment_types(): void
    {
        [$owner, $project] = $this->projectScenario();

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [$this->pdf('แผนงาน.pdf')],
            ])->assertOk();

        $attachment = WorkOrderListAttachment::query()
            ->where('work_order_list_id', $project->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)
            ->get(route('media.project-attachments.show', [$attachment, 'download' => 1]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertOriginalName($response->headers->get('Content-Disposition'), 'แผนงาน.pdf');
    }

    /**
     * ชื่อเดิมมาจากผู้ใช้ จึงต้องไม่หลุดเป็น path separator ลง header
     * มิฉะนั้น Symfony จะโยน InvalidArgumentException แล้วหน้าจอได้ 500 แทนไฟล์
     */
    public function test_a_name_with_path_separators_falls_back_to_its_basename(): void
    {
        $this->assertSame('งบ.xlsx', ProtectedMedia::dispositionName('../../etc/งบ.xlsx', 'job-attachments/1/hash.xlsx'));
        $this->assertSame('งบ.xlsx', ProtectedMedia::dispositionName('C:\\Users\\me\\งบ.xlsx', 'job-attachments/1/hash.xlsx'));
    }

    /** ไฟล์เก่าที่ยังไม่มี original_name ต้องได้ชื่อบนดิสก์ซึ่งยังมีนามสกุลติดมา ไม่ใช่เลข id */
    public function test_a_missing_original_name_falls_back_to_the_stored_file_name(): void
    {
        $this->assertSame('hash.xlsx', ProtectedMedia::dispositionName(null, 'job-attachments/1/hash.xlsx'));
        $this->assertSame('hash.xlsx', ProtectedMedia::dispositionName('   ', 'job-attachments/1/hash.xlsx'));
        $this->assertNull(ProtectedMedia::dispositionName(null, null));
    }

    private function assertOriginalName(?string $disposition, string $name): void
    {
        $disposition = (string) $disposition;

        // ชื่อไทยต้องออกเป็น filename*= แบบ UTF-8 ไม่ใช่หายไปเหลือแต่ fallback
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode($name), $disposition);
    }

    /** ชื่อบนดิสก์ถูก hash ตอนอัปโหลด ถ้าหลุดออกไปผู้ใช้จะได้ไฟล์ที่เปิดไม่ออก */
    private function assertHashIsHidden(?string $disposition, ?string $filePath): void
    {
        $this->assertStringNotContainsString(basename((string) $filePath), (string) $disposition);
    }

    /** @return array{0: User, 1: WorkOrder} */
    private function taskScenario(): array
    {
        $department = Department::create(['department_name' => 'ไฟล์แนบของงาน']);
        $owner = $this->member($department);

        $task = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $department->id,
            'job_topic' => 'ระบบจัดการงาน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$owner, $task];
    }

    /** @return array{0: User, 1: WorkOrderList} */
    private function projectScenario(): array
    {
        $department = Department::create(['department_name' => 'ไฟล์แนบโปรเจกต์']);
        $owner = $this->member($department);

        return [$owner, WorkOrderList::create(['user_id' => $owner->id, 'name' => 'ระบบจัดการงาน'])];
    }

    private function member(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
