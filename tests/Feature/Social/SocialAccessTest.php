<?php

namespace Tests\Feature\Social;

use App\Models\SocialPost;

/**
 * ใครเข้าชุมชนได้ และทุกแผนกเห็นฟีดเดียวกัน
 */
class SocialAccessTest extends SocialFeatureCase
{
    public function test_guests_are_sent_to_login_for_every_social_endpoint(): void
    {
        $post = $this->createPost($this->member());

        $this->get(route('social.index'))->assertRedirect(route('login'));
        $this->get(route('social.posts.index'))->assertRedirect(route('login'));
        $this->post(route('social.posts.store'), ['body' => 'x'])->assertRedirect(route('login'));
        $this->put(route('social.posts.like.store', $post))->assertRedirect(route('login'));
        $this->get(route('social.posts.comments.index', $post))->assertRedirect(route('login'));
        $this->post(route('social.posts.comments.store', $post), ['body' => 'x'])->assertRedirect(route('login'));

        $this->getJson(route('social.posts.index'))->assertUnauthorized();
        $this->assertSame(1, SocialPost::count());
    }

    public function test_every_role_that_can_sign_in_opens_the_same_community_page(): void
    {
        foreach ([
            'admin' => $this->member('admin', null),
            'staff' => $this->member('user', 'IT'),
            'head' => $this->member('user', 'HR', head: true),
            'viewer' => $this->member('viewer', 'Accounting'),
        ] as $role => $user) {
            $this->actingAs($user)->get(route('social.index'))
                ->assertOk()
                ->assertSee('Social Community')
                ->assertSee('คุณคิดอะไรอยู่...')
                ->assertSee('data-social-page', false)
                ->assertSee('id="socialPostModal"', false);
        }
    }

    public function test_inactive_accounts_cannot_use_the_community(): void
    {
        $user = $this->member();
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get(route('social.index'))->assertRedirect();
        $this->actingAs($user)->postJson(route('social.posts.store'), ['body' => 'x']);
        $this->assertSame(0, SocialPost::count());
    }

    public function test_posts_and_comments_cross_department_boundaries(): void
    {
        $it = $this->member('user', 'IT');
        $hr = $this->member('user', 'HR');
        $accounting = $this->member('user', 'Accounting');
        $admin = $this->member('admin', null);
        $viewer = $this->member('viewer', 'Audit');

        $post = $this->createPost($it, 'วันนี้อัปเดตระบบเรียบร้อยแล้วครับ');
        $this->createComment($post, $hr, 'ขอบคุณมากค่ะ');

        foreach ([$hr, $accounting, $admin, $viewer, $it] as $user) {
            $this->actingAs($user)->get(route('social.index'))
                ->assertOk()
                ->assertSee('วันนี้อัปเดตระบบเรียบร้อยแล้วครับ')
                ->assertSee('IT');

            $this->actingAs($user)->getJson(route('social.posts.comments.index', $post))
                ->assertOk()
                ->assertJsonPath('comments_count', 1)
                ->assertJsonPath('ok', true);
            $this->assertStringContainsString('ขอบคุณมากค่ะ', $this->actingAs($user)->getJson(route('social.posts.comments.index', $post))->json('html'));
        }
    }

    public function test_every_role_can_post_comment_and_like(): void
    {
        foreach (['admin' => null, 'user' => 'IT', 'viewer' => 'Audit'] as $role => $department) {
            $user = $this->member($role, $department);

            $postId = $this->actingAs($user)->withHeaders($this->ajax())
                ->post(route('social.posts.store'), ['body' => 'สวัสดีจาก '.$role])
                ->assertCreated()
                ->json('post_id');
            $post = SocialPost::findOrFail($postId);

            $this->actingAs($user)->withHeaders($this->ajax())->post(route('social.posts.comments.store', $post), ['body' => 'ok'])->assertCreated();
            $this->actingAs($user)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))->assertOk()->assertJsonPath('likes_count', 1);
        }
    }

    public function test_page_renders_without_inline_styles_scripts_or_handlers(): void
    {
        $author = $this->member();
        $post = $this->createPost($author, 'มีรูป');
        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'มีรูป', 'images' => [$this->image()]])
            ->assertOk();
        $this->createComment($post, $author);

        $content = $this->actingAs($author)->get(route('social.index'))->assertOk()->getContent();
        $main = substr($content, strpos($content, '<main class="main">'), strpos($content, '</main>') - strpos($content, '<main class="main">'));

        $this->assertStringContainsString('data-social-page', $main);
        $this->assertStringNotContainsString('<script', $main);
        $this->assertStringNotContainsString('<style', $main);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $main);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $main);
    }
}
