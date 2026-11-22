<?php

namespace Tests\Feature\Social;

use App\Models\Department;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ข้อมูลตั้งต้นของ test ชุมชน (Social Community)
 *
 * ทุก test ใช้ storage ปลอม (local = private, public = legacy) จึงไม่แตะไฟล์จริงบนเครื่อง
 */
abstract class SocialFeatureCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function member(string $role = 'user', ?string $department = 'IT', bool $head = false): User
    {
        $departmentId = $department === null
            ? null
            : Department::query()->firstOrCreate(['department_name' => $department])->id;

        $user = User::factory()->create([
            'role' => $role,
            'is_department_head' => $head,
            'department_id' => $departmentId,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $this->acknowledgeDailyBrief($user);

        return $user;
    }

    protected function createPost(User $author, ?string $body = 'โพสต์ทดสอบ', array $attributes = []): SocialPost
    {
        $post = new SocialPost(['body' => $body]);
        $post->forceFill(['user_id' => $author->id, ...$attributes])->save();

        return $post;
    }

    protected function createComment(SocialPost $post, User $author, string $body = 'ความคิดเห็น', ?SocialComment $parent = null): SocialComment
    {
        $comment = new SocialComment(['body' => $body]);
        $comment->forceFill([
            'social_post_id' => $post->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
        ])->save();

        return $comment;
    }

    protected function image(string $name = 'photo.jpg', int $width = 120, int $height = 80): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /** @return array<string, string> */
    protected function ajax(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }
}
