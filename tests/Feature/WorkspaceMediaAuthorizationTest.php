<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use App\Services\WorkspaceBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * การเสิร์ฟรูปภาพของกระดานไอเดีย
 *
 * รูปเป็นไฟล์ส่วนตัว การตรวจสิทธิ์อ้างจาก "กระดานเจ้าของไฟล์" ไม่ใช่จากตัวไฟล์
 * กระดานที่ตั้งเป็นเฉพาะแผนกจึงกันรูปของตัวเองด้วยกติกาชุดเดียวกับที่กันตัวกระดาน
 * ถ้าวันหนึ่งกติกาสองชุดนี้แยกออกจากกัน รูปจะรั่วทั้งที่หน้ากระดานยังได้ 403
 */
class WorkspaceMediaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEklEQVR4nGP8z8DwnwEJMCELAABbeQQBOHkgBQAAAABJRU5ErkJggg==';

    private Department $it;

    private Department $hr;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
        $this->staff = $this->user('user', $this->it);
    }

    public function test_an_image_on_an_organization_wide_board_is_served_to_everyone(): void
    {
        $attachment = $this->attachmentOn($this->boardWith('organization'));

        foreach ([
            'เจ้าของแผนก' => $this->staff,
            'พนักงานแผนกอื่น' => $this->user('user', $this->hr),
            'ผู้เข้าชม' => $this->user('viewer', $this->it),
            'ผู้ดูแลระบบ' => $this->user('admin', $this->it),
        ] as $label => $actor) {
            $this->actingAs($actor)
                ->get(route('media.workspace-board-attachments.show', $attachment))
                ->assertOk($label);
        }
    }

    public function test_an_image_on_a_department_private_board_is_hidden_from_outsiders(): void
    {
        $attachment = $this->attachmentOn($this->boardWith('department'));

        $this->actingAs($this->user('user', $this->hr))
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertForbidden();

        $this->actingAs($this->user('viewer', $this->it))
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertOk();
    }

    public function test_the_image_is_served_privately_and_without_caching(): void
    {
        $attachment = $this->attachmentOn($this->boardWith('organization'));

        $response = $this->actingAs($this->staff)
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * route นี้ต้องถูกประกาศก่อน /media/{path} ที่เป็น catch-all มิฉะนั้นคำขอจะ
     * ไปตกที่ MediaController::legacy() ซึ่งไม่รู้จักไฟล์ของกระดานเลยและตอบ 404
     */
    public function test_the_route_is_declared_before_the_media_catch_all(): void
    {
        $attachment = $this->attachmentOn($this->boardWith('organization'));

        $this->actingAs($this->staff)
            ->get('/media/workspace-board-attachments/'.$attachment->id)
            ->assertOk();
    }

    /**
     * แถวไฟล์แนบที่ path ถูกแก้ให้ชี้ออกนอกโฟลเดอร์ของกระดานต้องถูกปฏิเสธ
     * กันกรณีที่มีช่องทางอื่นเขียนค่าลงคอลัมน์นี้ในอนาคต
     */
    public function test_a_path_outside_the_boards_directory_is_refused(): void
    {
        $board = $this->boardWith('organization');

        $attachment = WorkspaceBoardAttachment::query()->create([
            'workspace_board_id' => $board->id,
            'file_path' => 'job-attachments/1/secret.pdf',
            'original_name' => 'secret.pdf',
            'file_type' => 'application/pdf',
        ]);

        $this->actingAs($this->staff)
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertNotFound();
    }

    public function test_traversal_in_the_stored_path_is_refused(): void
    {
        $board = $this->boardWith('organization');

        $attachment = WorkspaceBoardAttachment::query()->create([
            'workspace_board_id' => $board->id,
            'file_path' => 'workspace-board-attachments/'.$board->id.'/../../.env',
            'original_name' => 'env',
            'file_type' => 'text/plain',
        ]);

        $this->actingAs($this->staff)
            ->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertNotFound();
    }

    /**
     * สร้างแถวและไฟล์ตรง ๆ โดยไม่ผ่าน actingAs เพราะ actingAs ตั้งผู้ใช้ให้กับ
     * ทุกคำขอที่ตามมาในเทสต์เดียวกัน ถ้าอัปโหลดผ่าน HTTP ก่อน คำขอของ "ผู้ไม่
     * ล็อกอิน" จะยังถือสถานะล็อกอินอยู่ แล้วเทสต์นี้จะผ่านโดยไม่ได้ทดสอบอะไรเลย
     */
    public function test_guests_are_redirected_to_login(): void
    {
        $board = $this->boardWith('organization');
        $path = 'workspace-board-attachments/'.$board->id.'/guest.png';

        Storage::disk('local')->put($path, base64_decode(self::PNG_BASE64));

        $attachment = WorkspaceBoardAttachment::query()->create([
            'workspace_board_id' => $board->id,
            'file_path' => $path,
            'original_name' => 'guest.png',
            'file_type' => 'image/png',
        ]);

        $this->get(route('media.workspace-board-attachments.show', $attachment))
            ->assertRedirect(route('login'));
    }

    private function boardWith(string $visibility): WorkspaceBoard
    {
        $board = app(WorkspaceBoardService::class)
            ->create($this->staff, $this->it, 'กระดาน '.$visibility, $visibility);

        return $board;
    }

    private function attachmentOn(WorkspaceBoard $board): WorkspaceBoardAttachment
    {
        $this->actingAs($this->staff)
            ->postJson(route('workspace.boards.attachments.store', $board), [
                'images' => [UploadedFile::fake()->createWithContent('idea.png', base64_decode(self::PNG_BASE64))],
            ])
            ->assertOk();

        return WorkspaceBoardAttachment::query()->where('workspace_board_id', $board->id)->firstOrFail();
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
