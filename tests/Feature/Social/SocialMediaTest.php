<?php

namespace Tests\Feature\Social;

use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\TrashLog;
use App\Support\AttachmentPolicy;
use App\Support\AuditTrail;
use App\Support\TrashRetention;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * รูปภาพของโพสต์ — ชนิดไฟล์ ขนาด การเข้าถึง และการเก็บกวาดไฟล์
 */
class SocialMediaTest extends SocialFeatureCase
{
    public function test_jpeg_png_and_webp_are_accepted(): void
    {
        $author = $this->member();

        $postId = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'images' => [$this->image('a.jpg'), $this->image('b.png'), $this->image('c.webp')],
        ])->assertCreated()->json('post_id');

        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], SocialPost::findOrFail($postId)->media->pluck('mime_type')->all());
    }

    public function test_dangerous_or_unsupported_files_are_rejected_and_nothing_is_stored(): void
    {
        $author = $this->member();
        $png = UploadedFile::fake()->image('real.png')->getContent();

        foreach ([
            'php disguised as jpg' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),
            'php extension' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1; ?>'),
            'svg with script' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'gif' => UploadedFile::fake()->image('anim.gif'),
            'png renamed to jpg' => UploadedFile::fake()->createWithContent('mismatch.jpg', $png),
            'pdf' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ] as $case => $file) {
            $this->actingAs($author)->withHeaders($this->ajax())
                ->post(route('social.posts.store'), ['body' => 'x', 'images' => [$file]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('images');
            $this->assertSame(0, SocialPost::count(), $case);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_files_over_the_configured_size_are_rejected(): void
    {
        $author = $this->member();

        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'images' => [$this->image()->size(10241)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images' => 'รูป "photo.jpg" มีขนาดเกิน 10 MB']);

        config(['social.images.max_kilobytes' => 2048]);
        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'images' => [$this->image()->size(3000)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images' => 'รูป "photo.jpg" มีขนาดเกิน 2 MB']);

        $this->assertSame(0, SocialPost::count());
    }

    public function test_smart_ticket_image_rules_are_unchanged_by_the_shared_validator(): void
    {
        $webp = $this->image('ticket.webp');
        $this->assertSame('แนบได้เฉพาะรูป JPG หรือ PNG ("ticket.webp" ไม่ใช่รูปที่รองรับ)', AttachmentPolicy::imageRejectionReason($webp));
        $this->assertNull(AttachmentPolicy::imageRejectionReason($this->image('ticket.jpg')));
        $this->assertSame('รูป "big.jpg" มีขนาดเกิน 10 MB', AttachmentPolicy::imageRejectionReason($this->image('big.jpg')->size(10241)));
    }

    public function test_only_signed_in_users_can_open_images_and_never_through_the_public_disk(): void
    {
        $author = $this->member();
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['images' => [$this->image()]])->json('post_id'));
        $media = $post->media()->firstOrFail();
        $url = route('media.social-post-media.show', $media);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->get($url)->assertRedirect(route('login'));
        $this->getJson($url)->assertUnauthorized();

        $response = $this->actingAs($this->member('viewer', 'Audit'))->get($url)->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_media_row_pointing_outside_its_post_folder_is_never_served(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);
        Storage::disk('local')->put('job-attachments/1/secret.pdf', 'secret');

        $media = (new SocialPostMedia)->forceFill([
            'social_post_id' => $post->id, 'file_path' => 'job-attachments/1/secret.pdf', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'file_size' => 6, 'sort_order' => 0,
        ]);
        $media->save();

        $this->actingAs($author)->get(route('media.social-post-media.show', $media))->assertNotFound();

        $media->forceFill(['file_path' => 'social-posts/'.$post->id.'/../../job-attachments/1/secret.pdf'])->saveQuietly();
        $this->actingAs($author)->get(route('media.social-post-media.show', $media))->assertNotFound();
    }

    public function test_purging_a_trashed_post_removes_its_files_and_rows(): void
    {
        $author = $this->member();
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['body' => 'ลบถาวร', 'images' => [$this->image('a.jpg'), $this->image('b.jpg')]])->json('post_id'));
        $paths = $post->media->pluck('file_path')->all();
        $this->createComment($post, $author);

        $this->actingAs($author)->withHeaders($this->ajax())->delete(route('social.posts.destroy', $post))->assertOk();
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        TrashRetention::purge(TrashLog::query()->where('entity_type', SocialPost::class)->firstOrFail());

        $this->assertDatabaseMissing('social_posts', ['id' => $post->id]);
        $this->assertDatabaseCount('social_post_media', 0);
        $this->assertDatabaseCount('social_comments', 0);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_purging_an_account_removes_the_images_of_its_posts_including_trashed_ones(): void
    {
        $author = $this->member();
        $live = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['images' => [$this->image('live.jpg')]])->json('post_id'));
        $trashed = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['images' => [$this->image('old.jpg')]])->json('post_id'));
        $paths = [$live->media()->value('file_path'), $trashed->media()->value('file_path')];
        $trashed->delete();

        $admin = $this->member('admin', null);
        $this->actingAs($admin);
        AuditTrail::trash($author, $admin, ['user' => ['name' => $author->name]]);
        $author->delete();

        TrashRetention::purge(TrashLog::query()->where('entity_type', get_class($author))->firstOrFail());

        $this->assertDatabaseCount('social_posts', 0);
        $this->assertDatabaseCount('social_post_media', 0);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }
}
