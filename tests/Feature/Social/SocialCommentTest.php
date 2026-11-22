<?php

namespace Tests\Feature\Social;

use App\Models\ActivityLog;
use App\Models\SocialComment;

/**
 * ความคิดเห็นและการตอบกลับ (หนึ่งระดับ)
 */
class SocialCommentTest extends SocialFeatureCase
{
    public function test_a_comment_is_added_escaped_and_the_count_is_returned(): void
    {
        $post = $this->createPost($this->member('user', 'IT'), 'วันนี้อัปเดตระบบเรียบร้อยแล้วครับ');
        $hr = $this->member('user', 'HR');

        $response = $this->actingAs($hr)->withHeaders($this->ajax())->post(route('social.posts.comments.store', $post), [
            'body' => 'ขอบคุณมากค่ะ <b>bold</b>',
            'user_id' => $post->user_id,
        ]);

        $response->assertCreated()->assertJsonPath('comments_count', 1)->assertJsonPath('parent_id', null);
        $comment = SocialComment::findOrFail($response->json('comment_id'));
        $this->assertSame($hr->id, $comment->user_id);
        $this->assertStringContainsString('ขอบคุณมากค่ะ &lt;b&gt;bold&lt;/b&gt;', $response->json('html'));
        $this->assertStringNotContainsString('<b>bold</b>', $response->json('html'));
    }

    public function test_replies_are_one_level_deep_even_when_replying_to_a_reply(): void
    {
        $it = $this->member('user', 'IT');
        $hr = $this->member('user', 'HR');
        $post = $this->createPost($it, 'วันนี้อัปเดตระบบเรียบร้อยแล้วครับ');
        $root = $this->createComment($post, $hr, 'ขอบคุณมากค่ะ');

        $reply = $this->actingAs($it)->withHeaders($this->ajax())
            ->post(route('social.posts.comments.store', $post), ['body' => 'ยินดีครับ', 'parent_id' => $root->id])
            ->assertCreated()->assertJsonPath('parent_id', $root->id)->assertJsonPath('comments_count', 2);

        $this->actingAs($hr)->withHeaders($this->ajax())
            ->post(route('social.posts.comments.store', $post), ['body' => 'ตอบต่อ', 'parent_id' => $reply->json('comment_id')])
            ->assertCreated()->assertJsonPath('parent_id', $root->id);

        $page = $this->actingAs($hr)->getJson(route('social.posts.comments.index', $post))->assertOk();
        $html = $page->json('html');
        $this->assertSame(3, $page->json('comments_count'));
        $this->assertSame(1, substr_count($html, 'class="social-comment" '), 'มีความคิดเห็นหลักหนึ่งรายการ');
        $this->assertSame(2, substr_count($html, 'class="social-comment social-comment--reply"'));
        $this->assertSame(1, substr_count($html, 'data-social-reply-list'), 'การตอบกลับไม่มีรายการตอบกลับซ้อนของตัวเอง');
    }

    public function test_a_reply_cannot_target_a_comment_from_another_post(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);
        $elsewhere = $this->createComment($this->createPost($author, 'อีกโพสต์'), $author);

        $this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.comments.store', $post), ['body' => 'x', 'parent_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertSame(0, $post->comments()->count());
    }

    public function test_empty_comments_are_rejected(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);

        $this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.comments.store', $post), ['body' => "   \n "])
            ->assertUnprocessable()->assertJsonValidationErrors(['body' => 'กรุณาพิมพ์ความคิดเห็น']);
    }

    public function test_the_owner_edits_their_comment_and_nobody_else_can(): void
    {
        $author = $this->member();
        $post = $this->createPost($this->member('user', 'HR'));
        $comment = $this->createComment($post, $author, 'เดิม');

        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.comments.update', $comment), ['body' => 'แก้แล้ว'])
            ->assertOk()->assertJsonPath('comment_id', $comment->id);
        $this->assertSame('แก้แล้ว', $comment->fresh()->body);
        $this->assertNotNull($comment->fresh()->edited_at);

        foreach ([$this->member('user', 'Accounting'), $this->member('admin', null), $post->author] as $intruder) {
            $this->actingAs($intruder)->withHeaders($this->ajax())
                ->patch(route('social.comments.update', $comment), ['body' => 'แอบแก้'])
                ->assertForbidden();
        }
        $this->assertSame('แก้แล้ว', $comment->fresh()->body);
    }

    public function test_deleting_a_comment_takes_its_replies_and_updates_the_count(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);
        $root = $this->createComment($post, $author, 'หลัก');
        $this->createComment($post, $this->member('user', 'HR'), 'ตอบ 1', $root);
        $this->createComment($post, $author, 'ตอบ 2', $root);
        $this->createComment($post, $author, 'อีกความคิดเห็น');

        $this->actingAs($author)->withHeaders($this->ajax())->delete(route('social.comments.destroy', $root))
            ->assertOk()->assertJsonPath('comments_count', 1);

        $this->assertSoftDeleted($root);
        $this->assertSame(1, $post->comments()->count());
    }

    public function test_users_cannot_delete_other_comments_but_an_admin_can_moderate(): void
    {
        $post = $this->createPost($this->member());
        $comment = $this->createComment($post, $this->member('user', 'HR'));

        $this->actingAs($this->member('user', 'Accounting'))->withHeaders($this->ajax())
            ->delete(route('social.comments.destroy', $comment))->assertForbidden();
        $this->assertNotSoftDeleted($comment);

        $admin = $this->member('admin', null);
        $this->actingAs($admin)->withHeaders($this->ajax())->delete(route('social.comments.destroy', $comment))->assertOk();
        $this->assertSoftDeleted($comment);
        $this->assertTrue(ActivityLog::query()->where('subject_type', SocialComment::class)->where('user_id', $admin->id)->exists());
    }

    public function test_comments_are_closed_once_the_post_is_deleted(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);
        $comment = $this->createComment($post, $author);
        $post->delete();

        $this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.comments.store', $post), ['body' => 'ยังคอมเมนต์ได้ไหม'])
            ->assertNotFound();
        $this->actingAs($author)->getJson(route('social.posts.comments.index', $post))->assertNotFound();
        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.comments.update', $comment), ['body' => 'แก้'])->assertNotFound();

        $this->assertSame(1, SocialComment::count());
    }

    public function test_comments_load_newest_page_first_and_older_pages_by_cursor(): void
    {
        config(['social.comment_page_size' => 3]);
        $author = $this->member();
        $post = $this->createPost($author);
        $comments = collect(range(1, 5))->map(fn (int $i) => $this->createComment($post, $author, 'ความเห็นที่-'.$i.'-จบ'));

        $first = $this->actingAs($author)->getJson(route('social.posts.comments.index', $post))->assertOk();
        $first->assertJsonPath('next_cursor', $comments[2]->id)->assertJsonPath('comments_count', 5);
        $this->assertMatchesRegularExpression('/ความเห็นที่-3-จบ.*ความเห็นที่-4-จบ.*ความเห็นที่-5-จบ/s', $first->json('html'));
        $this->assertStringNotContainsString('ความเห็นที่-2-จบ', $first->json('html'));

        $older = $this->actingAs($author)->getJson(route('social.posts.comments.index', [$post, 'before' => $comments[2]->id]))->assertOk();
        $older->assertJsonPath('next_cursor', null);
        $this->assertMatchesRegularExpression('/ความเห็นที่-1-จบ.*ความเห็นที่-2-จบ/s', $older->json('html'));
    }

    public function test_comment_actions_are_rendered_only_for_permitted_users(): void
    {
        $author = $this->member();
        $post = $this->createPost($author);
        $comment = $this->createComment($post, $author);
        $edit = 'data-update-url="'.route('social.comments.update', $comment).'"';
        $delete = 'data-destroy-url="'.route('social.comments.destroy', $comment).'"';

        $mine = $this->actingAs($author)->getJson(route('social.posts.comments.index', $post))->json('html');
        $this->assertStringContainsString($edit, $mine);
        $this->assertStringContainsString($delete, $mine);

        $theirs = $this->actingAs($this->member('user', 'HR'))->getJson(route('social.posts.comments.index', $post))->json('html');
        $this->assertStringNotContainsString($edit, $theirs);
        $this->assertStringNotContainsString($delete, $theirs);
        $this->assertStringContainsString('data-social-comment-reply', $theirs);

        $admin = $this->actingAs($this->member('admin', null))->getJson(route('social.posts.comments.index', $post))->json('html');
        $this->assertStringNotContainsString($edit, $admin);
        $this->assertStringContainsString($delete, $admin);
    }
}
