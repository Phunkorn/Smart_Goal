<?php

namespace Tests\Feature\Social;

use App\Models\ActivityLog;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\TrashLog;
use App\Support\TrashRetention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * สร้าง แก้ไข ลบ และฟีดของโพสต์ในชุมชน
 */
class SocialPostTest extends SocialFeatureCase
{
    public function test_a_text_post_keeps_line_breaks_and_emoji_and_is_owned_by_the_signed_in_user(): void
    {
        $author = $this->member();
        $other = $this->member('user', 'HR');

        $response = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => "  บรรทัดแรก\r\nบรรทัดสอง 🎉👨‍💻  ",
            // mass assignment: ส่ง user_id ของคนอื่นมาต้องไม่มีผล
            'user_id' => $other->id,
        ]);

        $response->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('message', 'โพสต์เรียบร้อยแล้ว');
        $post = SocialPost::findOrFail($response->json('post_id'));

        $this->assertSame($author->id, $post->user_id);
        $this->assertSame("บรรทัดแรก\nบรรทัดสอง 🎉👨‍💻", $post->body);
        $this->assertStringContainsString('id="social-post-'.$post->id.'"', $response->json('html'));
        $this->assertStringContainsString("บรรทัดแรก\nบรรทัดสอง 🎉👨‍💻", $response->json('html'));
    }

    public function test_post_text_is_rendered_escaped_never_as_html(): void
    {
        $author = $this->member();
        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => '<script>alert(1)</script><img src=x onerror=alert(2)>',
        ])->assertCreated();

        $this->actingAs($this->member('user', 'HR'))->get(route('social.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_post_with_text_and_images_stores_private_files_in_order(): void
    {
        $author = $this->member();

        $response = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => 'ภาพกิจกรรม',
            'images' => [$this->image('first.jpg', 300, 200), $this->image('second.png', 100, 100)],
        ])->assertCreated();

        $post = SocialPost::with('media')->findOrFail($response->json('post_id'));
        $this->assertCount(2, $post->media);
        $this->assertSame([0, 1], $post->media->pluck('sort_order')->all());
        $this->assertSame(['image/jpeg', 'image/png'], $post->media->pluck('mime_type')->all());
        $this->assertSame([300, 100], $post->media->pluck('width')->all());

        foreach ($post->media as $media) {
            $this->assertStringStartsWith('social-posts/'.$post->id.'/', $media->file_path);
            Storage::disk('local')->assertExists($media->file_path);
            Storage::disk('public')->assertMissing($media->file_path);
            // URL ในหน้าเว็บเป็น route ที่ตรวจสิทธิ์ ไม่ใช่ path จริงของไฟล์
            $this->assertStringContainsString(route('media.social-post-media.show', $media), $response->json('html'));
            $this->assertStringNotContainsString($media->file_path, $response->json('html'));
        }
    }

    public function test_an_image_only_post_is_allowed(): void
    {
        $author = $this->member();

        $response = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => '   ',
            'images' => [$this->image()],
        ])->assertCreated();

        $post = SocialPost::findOrFail($response->json('post_id'));
        $this->assertNull($post->body);
        $this->assertSame(1, $post->media()->count());
    }

    public function test_an_empty_post_is_rejected(): void
    {
        $author = $this->member();

        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), ['body' => "  \n  "])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'กรุณาพิมพ์ข้อความหรือเลือกรูปภาพอย่างน้อย 1 รูป']);

        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [])
            ->assertUnprocessable();

        $this->assertSame(0, SocialPost::count());
    }

    public function test_post_length_and_image_count_follow_server_configuration(): void
    {
        config(['social.post_max_length' => 10, 'social.images.max_per_post' => 2]);
        $author = $this->member();

        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), ['body' => str_repeat('ก', 11)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => 'ok',
            'images' => [$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images' => 'แนบรูปได้ไม่เกิน 2 รูปต่อโพสต์']);

        $this->assertSame(0, SocialPost::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_owner_edits_text_removes_and_adds_images(): void
    {
        $author = $this->member();
        $postId = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => 'เดิม',
            'images' => [$this->image('keep.jpg'), $this->image('drop.jpg')],
        ])->json('post_id');
        $post = SocialPost::with('media')->findOrFail($postId);
        [$keep, $drop] = $post->media->all();

        $response = $this->actingAs($author)->withHeaders($this->ajax())->patch(route('social.posts.update', $post), [
            'body' => 'แก้ไขแล้ว',
            'remove_media' => [$drop->id],
            'images' => [$this->image('new.png')],
        ]);

        $response->assertOk()->assertJsonPath('message', 'บันทึกการแก้ไขโพสต์แล้ว');
        $post->refresh()->load('media');
        $this->assertSame('แก้ไขแล้ว', $post->body);
        $this->assertNotNull($post->edited_at);
        $this->assertSame([$keep->id], $post->media->take(1)->pluck('id')->all());
        $this->assertSame([0, 1], $post->media->pluck('sort_order')->all());
        $this->assertSame('image/png', $post->media[1]->mime_type);
        $this->assertDatabaseMissing('social_post_media', ['id' => $drop->id]);
        Storage::disk('local')->assertMissing($drop->file_path);
        Storage::disk('local')->assertExists($keep->file_path);
        $this->assertStringContainsString('แก้ไขแล้ว', $response->json('html'));
    }

    public function test_an_edit_cannot_remove_images_of_another_post_or_leave_the_post_empty(): void
    {
        $author = $this->member();
        $mine = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['images' => [$this->image()]])->json('post_id'));
        $theirs = SocialPost::findOrFail($this->actingAs($this->member('user', 'HR'))->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['images' => [$this->image()]])->json('post_id'));
        $theirMedia = $theirs->media()->firstOrFail();

        $this->actingAs($author)->withHeaders($this->ajax())->patch(route('social.posts.update', $mine), [
            'body' => 'x',
            'remove_media' => [$theirMedia->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('remove_media');
        $this->assertDatabaseHas('social_post_media', ['id' => $theirMedia->id]);
        Storage::disk('local')->assertExists($theirMedia->file_path);

        $this->actingAs($author)->withHeaders($this->ajax())->patch(route('social.posts.update', $mine), [
            'body' => '',
            'remove_media' => [$mine->media()->value('id')],
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(1, $mine->media()->count());
    }

    public function test_nobody_edits_someone_elses_post_not_even_an_admin(): void
    {
        $post = $this->createPost($this->member(), 'ของฉัน');

        foreach ([$this->member('user', 'HR'), $this->member('admin', null), $this->member('viewer', 'Audit')] as $intruder) {
            $this->actingAs($intruder)->withHeaders($this->ajax())
                ->patch(route('social.posts.update', $post), ['body' => 'แก้โดยคนอื่น'])
                ->assertForbidden();
        }

        $this->assertSame('ของฉัน', $post->fresh()->body);
    }

    public function test_the_owner_deletes_a_post_into_the_trash_and_files_stay_until_purge(): void
    {
        $author = $this->member();
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['body' => 'จะลบ', 'images' => [$this->image()]])->json('post_id'));
        $media = $post->media()->firstOrFail();

        $this->actingAs($author)->withHeaders($this->ajax())->delete(route('social.posts.destroy', $post))
            ->assertOk()->assertJsonPath('post_id', $post->id);

        $this->assertSoftDeleted($post);
        $this->assertDatabaseHas('trash_logs', ['entity_type' => SocialPost::class, 'entity_id' => $post->id]);
        Storage::disk('local')->assertExists($media->file_path);

        $this->actingAs($this->member('user', 'HR'))->get(route('social.index'))->assertOk()->assertDontSee('จะลบ');
        $this->actingAs($author)->get(route('media.social-post-media.show', $media))->assertNotFound();
        $this->actingAs($author)->withHeaders($this->ajax())->delete(route('social.posts.destroy', $post))->assertNotFound();
    }

    public function test_users_cannot_delete_other_posts_but_an_admin_can_moderate(): void
    {
        $post = $this->createPost($this->member(), 'ไม่เหมาะสม');

        $this->actingAs($this->member('user', 'HR'))->withHeaders($this->ajax())
            ->delete(route('social.posts.destroy', $post))->assertForbidden();
        $this->actingAs($this->member('viewer', 'Audit'))->withHeaders($this->ajax())
            ->delete(route('social.posts.destroy', $post))->assertForbidden();
        $this->assertNotSoftDeleted($post);

        $admin = $this->member('admin', null);
        $this->actingAs($admin)->withHeaders($this->ajax())->delete(route('social.posts.destroy', $post))->assertOk();

        $this->assertSoftDeleted($post);
        $this->assertTrue(ActivityLog::query()
            ->where('subject_type', SocialPost::class)->where('subject_id', $post->id)
            ->where('action', 'deleted')->where('user_id', $admin->id)->exists());
    }

    public function test_the_post_menu_only_offers_actions_the_viewer_is_allowed_to_take(): void
    {
        $author = $this->member();
        $post = $this->createPost($author, 'เมนู');

        $this->actingAs($author)->get(route('social.index'))
            ->assertSee('data-update-url="'.route('social.posts.update', $post).'"', false)
            ->assertSee('data-destroy-url="'.route('social.posts.destroy', $post).'"', false);

        // ระบุทั้ง attribute — URL แก้ไข/ลบ (/social/posts/{id}) เป็นส่วนต้นของ URL ถูกใจ (/social/posts/{id}/like)
        $this->actingAs($this->member('user', 'HR'))->get(route('social.index'))
            ->assertDontSee('data-update-url="'.route('social.posts.update', $post).'"', false)
            ->assertDontSee('data-destroy-url="'.route('social.posts.destroy', $post).'"', false)
            ->assertDontSee('data-social-post-edit', false);

        $this->actingAs($this->member('admin', null))->get(route('social.index'))
            ->assertDontSee('data-update-url="'.route('social.posts.update', $post).'"', false)
            ->assertSee('data-destroy-url="'.route('social.posts.destroy', $post).'"', false)
            ->assertSee('ลบโพสต์ (ผู้ดูแลระบบ)');
    }

    public function test_a_deleted_post_can_be_restored_from_the_trash_with_its_images(): void
    {
        $author = $this->member();
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['body' => 'กู้คืนได้', 'images' => [$this->image()]])->json('post_id'));
        $this->actingAs($author)->withHeaders($this->ajax())->delete(route('social.posts.destroy', $post))->assertOk();

        $trash = TrashLog::query()->where('entity_type', SocialPost::class)->firstOrFail();
        $this->assertStringContainsString('กู้คืนได้', TrashRetention::summary($trash)['name']);
        $this->assertSame('โพสต์ชุมชน', TrashRetention::summary($trash)['entity_label']);

        $this->actingAs($this->member('admin', null));
        TrashRetention::restore($trash);

        $this->assertNotSoftDeleted($post);
        $media = $post->media()->firstOrFail();
        $this->actingAs($author)->get(route('media.social-post-media.show', $media))->assertOk();
    }

    public function test_the_feed_pages_by_cursor_newest_first_without_duplicates(): void
    {
        config(['social.feed_page_size' => 5]);
        $author = $this->member();
        $posts = collect(range(1, 12))->map(fn (int $i) => $this->createPost($author, 'โพสต์ลำดับ-'.$i.'-จบ'));

        $page = $this->actingAs($author)->get(route('social.index'))->assertOk();
        $page->assertSeeInOrder(['โพสต์ลำดับ-12-จบ', 'โพสต์ลำดับ-11-จบ', 'โพสต์ลำดับ-8-จบ'])
            ->assertDontSee('โพสต์ลำดับ-7-จบ')
            ->assertSee('data-next-cursor="'.$posts[7]->id.'"', false);

        // มีคนโพสต์ใหม่ระหว่างที่ผู้ใช้ยังไม่กดโหลดเพิ่ม — หน้าถัดไปต้องไม่ซ้ำและไม่ข้าม
        $this->createPost($this->member('user', 'HR'), 'โพสต์ใหม่ระหว่างทาง');

        $second = $this->actingAs($author)->getJson(route('social.posts.index', ['before' => $posts[7]->id]))->assertOk();
        $second->assertJsonPath('count', 5)->assertJsonPath('next_cursor', $posts[2]->id);
        foreach ([7, 6, 5, 4, 3] as $i) {
            $this->assertStringContainsString('โพสต์ลำดับ-'.$i.'-จบ', $second->json('html'));
        }
        $this->assertStringNotContainsString('โพสต์ใหม่ระหว่างทาง', $second->json('html'));

        $last = $this->actingAs($author)->getJson(route('social.posts.index', ['before' => $posts[2]->id]))->assertOk();
        $last->assertJsonPath('count', 2)->assertJsonPath('next_cursor', null);

        $this->actingAs($author)->getJson(route('social.posts.index', ['before' => 'abc']))->assertUnprocessable();
    }

    public function test_the_feed_query_count_does_not_grow_with_the_number_of_posts(): void
    {
        $viewer = $this->member('user', 'HR');

        $count = function () use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->getJson(route('social.posts.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $seed = function (int $howMany): void {
            foreach (range(1, $howMany) as $i) {
                $author = $this->member('user', 'Dept '.$i);
                $post = $this->createPost($author, 'p'.$i);
                (new SocialPostMedia)->forceFill([
                    'social_post_id' => $post->id, 'file_path' => 'social-posts/'.$post->id.'/x.jpg', 'original_name' => 'x.jpg',
                    'mime_type' => 'image/jpeg', 'file_size' => 1, 'sort_order' => 0,
                ])->save();
                $this->createComment($post, $author);
            }
        };

        $seed(2);
        $few = $count();
        $seed(8);
        $many = $count();

        $this->assertSame($few, $many, 'จำนวนคิวรีของฟีดต้องคงที่ ไม่เพิ่มตามจำนวนโพสต์ (N+1)');
    }
}
