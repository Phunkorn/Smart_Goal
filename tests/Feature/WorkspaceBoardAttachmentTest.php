<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use App\Services\WorkspaceBoardService;
use App\Support\ProtectedMedia;
use App\Support\WorkspaceDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * การแนบรูปภาพบนกระดานไอเดีย
 *
 * ประเด็นหลักคือฟีเจอร์นี้ต้องรัดกุมกว่าไฟล์แนบของใบงาน โดยไม่ไปลดทอน
 * AttachmentPolicy ที่ใช้ร่วมกัน และ path ของไฟล์ต้องไม่หลุดออกไปที่ฝั่งเบราว์เซอร์
 *
 * ไม่ใช้ UploadedFile::fake()->image() เพราะต้องพึ่งส่วนขยาย GD ซึ่งไม่ได้เปิด
 * ในทุกเครื่อง ใช้ไฟล์ PNG จริงขนาดเล็กที่ฝังไว้เป็น base64 แทน
 */
class WorkspaceBoardAttachmentTest extends TestCase
{
    use RefreshDatabase;

    /** PNG ขนาด 2x1 พิกเซล พอให้ getimagesize() อ่านขนาดจริงได้ */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEklEQVR4nGP8z8DwnwEJMCELAABbeQQBOHkgBQAAAABJRU5ErkJggg==';

    private Department $it;

    private User $staff;

    private WorkspaceBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->it = Department::create(['department_name' => 'IT']);
        $this->staff = $this->user('user', $this->it);
        $this->board = app(WorkspaceBoardService::class)
            ->create($this->staff, $this->it, 'กระดานทดสอบ', 'organization');
    }

    public function test_uploading_an_image_stores_the_file_and_returns_a_route_not_a_path(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $attachment = WorkspaceBoardAttachment::query()->firstOrFail();

        $this->assertSame($this->board->id, $attachment->workspace_board_id);
        $this->assertSame('idea.png', $attachment->original_name);
        $this->assertSame($this->staff->id, $attachment->uploaded_by);
        $this->assertStringStartsWith('workspace-board-attachments/'.$this->board->id.'/', $attachment->file_path);

        // ขนาดจริงถูกอ่านจากไฟล์ ไม่ใช่เชื่อค่าที่ฝั่งเบราว์เซอร์ส่งมา
        $this->assertSame(2, $attachment->image_width);
        $this->assertSame(1, $attachment->image_height);

        // ห้ามเปิดเผย path ของไฟล์ส่วนตัวออกไปที่ JSON
        $payload = $response->json();
        $this->assertSame(
            route('media.workspace-board-attachments.show', $attachment),
            $payload['attachments'][0]['src']
        );
        $this->assertStringNotContainsString($attachment->file_path, $response->getContent());
    }

    /**
     * AttachmentPolicy ที่ใช้ร่วมกับใบงานอนุญาต docx/xlsx/zip กระดานต้องแคบกว่านั้น
     * โดยไม่ไปแก้ AttachmentPolicy ซึ่งจะกระทบงานโครงการ
     */
    public function test_documents_allowed_for_work_orders_are_rejected_on_a_board(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [UploadedFile::fake()->createWithContent('spec.pdf', '%PDF-1.4 test')],
            ])
            ->assertStatus(422)
            // Laravel รายงานความผิดพลาดของกฎ images.* ด้วยคีย์รายไฟล์ ไม่ใช่คีย์รวม
            ->assertJsonValidationErrors('images.0');

        $this->assertSame(0, WorkspaceBoardAttachment::query()->count());
    }

    public function test_an_image_beyond_the_size_cap_is_rejected(): void
    {
        $oversized = UploadedFile::fake()
            ->createWithContent('huge.png', base64_decode(self::PNG_BASE64))
            ->size(WorkspaceDesign::IMAGE_MAX_KILOBYTES + 1);

        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$oversized],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');
    }

    /**
     * เพดานต้องนับรวมรูปที่มีอยู่แล้ว ไม่ใช่นับเฉพาะที่ส่งมาในคำขอนี้
     * ไม่งั้นอัปโหลดหลายรอบก็ทะลุเพดานได้
     */
    public function test_the_per_board_image_cap_counts_existing_images(): void
    {
        for ($index = 0; $index < WorkspaceDesign::MAX_IMAGES; $index++) {
            WorkspaceBoardAttachment::query()->create([
                'workspace_board_id' => $this->board->id,
                'file_path' => 'workspace-board-attachments/'.$this->board->id.'/seed-'.$index.'.png',
                'original_name' => 'seed.png',
                'file_type' => 'image/png',
            ]);
        }

        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('one-more.png')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    }

    public function test_deleting_an_attachment_removes_the_stored_file(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertOk();

        $attachment = WorkspaceBoardAttachment::query()->firstOrFail();
        $path = $attachment->file_path;

        $this->assertNotNull(ProtectedMedia::attachmentAbsolutePath($path));

        $this->actingAs($this->staff)
            ->deleteJson(route('workspace.boards.attachments.destroy', [$this->board, $attachment]))
            ->assertOk();

        // ไฟล์แนบเข้าถังขยะ 30 วัน แถวหายจากรายการปกติแต่ตัวไฟล์ต้องอยู่ต่อ
        // เพื่อให้ปุ่มกู้คืนในหน้า Audit Log คืนไฟล์ที่เปิดได้จริง ไม่ใช่แถวเปล่า
        $this->assertSame(0, WorkspaceBoardAttachment::query()->count());
        $this->assertSame(1, WorkspaceBoardAttachment::onlyTrashed()->count());
        Storage::disk('local')->assertExists($path);
    }

    public function test_an_attachment_from_another_board_cannot_be_deleted_through_this_board(): void
    {
        $otherBoard = app(WorkspaceBoardService::class)
            ->create($this->staff, $this->it, 'กระดานอื่น', 'organization');

        $attachment = WorkspaceBoardAttachment::query()->create([
            'workspace_board_id' => $otherBoard->id,
            'file_path' => 'workspace-board-attachments/'.$otherBoard->id.'/x.png',
            'original_name' => 'x.png',
            'file_type' => 'image/png',
        ]);

        $this->actingAs($this->staff)
            ->deleteJson(route('workspace.boards.attachments.destroy', [$this->board, $attachment]))
            ->assertNotFound();

        $this->assertSame(1, WorkspaceBoardAttachment::query()->count());
    }

    public function test_outsiders_and_viewers_cannot_upload(): void
    {
        $hr = Department::create(['department_name' => 'HR']);

        // คนนอกแผนกเปิดกระดานสาธารณะดูได้ แต่แนบรูปไม่ได้
        $this->actingAs($this->user('user', $hr))
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertForbidden();

        $this->actingAs($this->user('viewer', $this->it))
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkspaceBoardAttachment::query()->count());
    }

    /**
     * ชิ้นงานรูปภาพในเอกสารต้องอ้างไฟล์ของกระดานตัวเองเท่านั้น มิฉะนั้นจะเป็น
     * การข้ามการตรวจสิทธิ์ของ MediaController ที่ตัดสินจากกระดานเจ้าของไฟล์
     */
    public function test_the_document_rejects_an_image_element_pointing_at_another_boards_file(): void
    {
        $otherBoard = app(WorkspaceBoardService::class)
            ->create($this->staff, $this->it, 'กระดานอื่น', 'organization');

        $foreign = WorkspaceBoardAttachment::query()->create([
            'workspace_board_id' => $otherBoard->id,
            'file_path' => 'workspace-board-attachments/'.$otherBoard->id.'/x.png',
            'original_name' => 'x.png',
            'file_type' => 'image/png',
        ]);

        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => ['schema' => 1, 'elements' => [[
                    'id' => 'i1', 'type' => 'image', 'z' => 1,
                    'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                    'attachmentId' => $foreign->id,
                ]]],
            ])
            ->assertStatus(422);
    }

    public function test_an_image_element_pointing_at_an_owned_file_round_trips_with_a_media_url(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertOk();

        $attachment = WorkspaceBoardAttachment::query()->firstOrFail();

        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => ['schema' => 1, 'elements' => [[
                    'id' => 'i1', 'type' => 'image', 'z' => 1,
                    'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                    'attachmentId' => $attachment->id,
                    // ฝั่งเบราว์เซอร์ส่ง src กลับมาด้วย ตัวกรองต้องตัดทิ้ง
                    'src' => 'https://evil.example/x.png',
                ]]],
            ])
            ->assertOk();

        $element = $this->actingAs($this->staff)
            ->getJson(route('workspace.boards.document', $this->board))
            ->assertOk()
            ->json('document.elements.0');

        $this->assertSame($attachment->id, $element['attachmentId']);
        $this->assertSame(route('media.workspace-board-attachments.show', $attachment), $element['src']);
    }

    /**
     * ลบรูปทิ้งขณะที่อีกคนเปิดกระดานค้างไว้ ต้องไม่ทำให้หน้าจอแสดงรูปแตก
     */
    public function test_image_elements_referencing_a_deleted_file_are_dropped_when_reading(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $this->board), [
                'images' => [$this->png('idea.png')],
            ])
            ->assertOk();

        $attachment = WorkspaceBoardAttachment::query()->firstOrFail();

        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => ['schema' => 1, 'elements' => [[
                    'id' => 'i1', 'type' => 'image', 'z' => 1,
                    'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                    'attachmentId' => $attachment->id,
                ]]],
            ])
            ->assertOk();

        $attachment->delete();

        $elements = $this->actingAs($this->staff)
            ->getJson(route('workspace.boards.document', $this->board))
            ->assertOk()
            ->json('document.elements');

        $this->assertSame([], $elements);
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    private function user(string $role, Department $department): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }
}
