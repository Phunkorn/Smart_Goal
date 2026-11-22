<?php

namespace Tests\Feature\Social;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\SocialPost;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * การมองเห็นโพสต์: ทุกคนในองค์กร / เฉพาะแผนกของผู้โพสต์
 *
 * ระบบไม่มีแนวคิด "สาขา" ในข้อมูลผู้ใช้ (มีแค่ users.department_id) พนักงานต่างสาขาที่อยู่แผนกเดียวกัน
 * จึงเท่ากับบัญชีสองบัญชีที่ department_id เดียวกัน
 */
class SocialVisibilityTest extends SocialFeatureCase
{
    private function departmentPost(User $author, string $body = 'ประกาศภายในแผนก IT', array $attributes = []): SocialPost
    {
        return $this->createPost($author, $body, [
            'visibility' => SocialPost::VISIBILITY_DEPARTMENT,
            'target_department_id' => $author->department_id,
            ...$attributes,
        ]);
    }

    private function feedHtml(User $viewer): string
    {
        return $this->actingAs($viewer)->get(route('social.index'))->assertOk()->getContent();
    }

    public function test_an_everyone_post_is_visible_to_every_department_and_role(): void
    {
        $this->createPost($this->member('user', 'IT'), 'ข่าวถึงทุกแผนก');

        foreach ([$this->member('user', 'HR'), $this->member('admin', null), $this->member('viewer', 'Accounting')] as $viewer) {
            $this->assertStringContainsString('ข่าวถึงทุกแผนก', $this->feedHtml($viewer));
        }
    }

    public function test_a_department_post_reaches_only_that_department_regardless_of_role(): void
    {
        $author = $this->member('user', 'IT');
        $this->departmentPost($author, 'เฉพาะทีมไอทีเท่านั้น');

        // แผนกเดียวกัน — ต่างสาขา (บัญชีอื่นในแผนกเดียวกัน) ต่างบทบาท และหัวหน้าแผนก เห็นทั้งหมด
        foreach ([
            'colleague at another branch' => $this->member('user', 'IT'),
            'department head' => $this->member('user', 'IT', head: true),
            'viewer in IT' => $this->member('viewer', 'IT'),
            'admin in IT' => $this->member('admin', 'IT'),
            'author' => $author,
        ] as $case => $viewer) {
            $this->assertStringContainsString('เฉพาะทีมไอทีเท่านั้น', $this->feedHtml($viewer), $case);
        }

        // ต่างแผนก — admin ไม่ได้รับสิทธิ์พิเศษเพียงเพราะเป็น admin และไม่มีชื่อผู้โพสต์หลุดไปด้วย
        foreach ([
            'HR' => $this->member('user', 'HR'),
            'Accounting' => $this->member('user', 'Accounting'),
            'admin elsewhere' => $this->member('admin', 'Management'),
            'admin without department' => $this->member('admin', null),
            'no department' => $this->member('user', null),
        ] as $case => $viewer) {
            $html = $this->feedHtml($viewer);
            $this->assertStringNotContainsString('เฉพาะทีมไอทีเท่านั้น', $html, $case);
            $this->assertStringNotContainsString('data-post-id', $html, $case);
            $this->assertStringNotContainsString(e($author->name), $html, $case);

            $json = $this->actingAs($viewer)->getJson(route('social.posts.index'))->assertOk();
            $json->assertJsonPath('count', 0);
            $this->assertStringNotContainsString('เฉพาะทีมไอทีเท่านั้น', (string) $json->getContent(), $case);
        }
    }

    public function test_every_endpoint_answers_404_for_a_post_the_user_cannot_see(): void
    {
        $author = $this->member('user', 'IT');
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => 'ลับเฉพาะแผนก',
            'visibility' => 'department',
            'images' => [$this->image()],
        ])->assertCreated()->json('post_id'));
        $comment = $this->createComment($post, $author, 'ความเห็นภายในทีม');
        $media = $post->media()->firstOrFail();

        foreach ([$this->member('user', 'HR'), $this->member('admin', 'Management')] as $outsider) {
            $this->actingAs($outsider)->withHeaders($this->ajax());
            $this->getJson(route('social.posts.comments.index', $post))->assertNotFound();
            $this->post(route('social.posts.comments.store', $post), ['body' => 'แอบตอบ'])->assertNotFound();
            $this->put(route('social.posts.like.store', $post))->assertNotFound();
            $this->delete(route('social.posts.like.destroy', $post))->assertNotFound();
            $this->patch(route('social.posts.update', $post), ['body' => 'แก้'])->assertNotFound();
            $this->delete(route('social.posts.destroy', $post))->assertNotFound();
            $this->patch(route('social.comments.update', $comment), ['body' => 'แก้'])->assertNotFound();
            $this->delete(route('social.comments.destroy', $comment))->assertNotFound();
            $this->flushHeaders();
            $this->get(route('media.social-post-media.show', $media))->assertNotFound();
        }

        $this->assertNotSoftDeleted($post);
        $this->assertSame(1, $post->comments()->count());
        $this->assertSame(0, $post->likes()->count());
        $this->assertSame('ลับเฉพาะแผนก', $post->fresh()->body);

        // คนในแผนกเดียวกันใช้ได้ทุกอย่างตามปกติ
        $colleague = $this->member('user', 'IT');
        $this->actingAs($colleague)->get(route('media.social-post-media.show', $media))->assertOk();
        $this->actingAs($colleague)->getJson(route('social.posts.comments.index', $post))->assertOk()->assertJsonPath('comments_count', 1);
        $this->actingAs($colleague)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))->assertOk();
    }

    public function test_an_admin_moderates_department_posts_only_inside_their_own_department(): void
    {
        $post = $this->departmentPost($this->member('user', 'IT'));

        $this->actingAs($this->member('admin', 'HR'))->withHeaders($this->ajax())
            ->delete(route('social.posts.destroy', $post))->assertNotFound();
        $this->assertNotSoftDeleted($post);

        $this->actingAs($this->member('admin', 'IT'))->withHeaders($this->ajax())
            ->delete(route('social.posts.destroy', $post))->assertOk();
        $this->assertSoftDeleted($post);
    }

    public function test_guests_never_see_a_post_or_its_image(): void
    {
        $author = $this->member('user', 'IT');
        $post = SocialPost::findOrFail($this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'visibility' => 'department',
            'images' => [$this->image()],
        ])->json('post_id'));

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->get(route('social.index'))->assertRedirect(route('login'));
        $this->get(route('media.social-post-media.show', $post->media()->firstOrFail()))->assertRedirect(route('login'));
        $this->getJson(route('social.posts.comments.index', $post))->assertUnauthorized();
    }

    public function test_the_target_department_comes_from_the_author_not_the_request(): void
    {
        $author = $this->member('user', 'IT');
        $hr = Department::query()->firstOrCreate(['department_name' => 'HR']);

        $postId = $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), [
            'body' => 'พยายามส่งแผนกอื่น',
            'visibility' => 'department',
            'target_department_id' => $hr->id,
            'department_id' => $hr->id,
        ])->assertCreated()->json('post_id');

        $post = SocialPost::findOrFail($postId);
        $this->assertSame('department', $post->visibility);
        $this->assertSame($author->department_id, $post->target_department_id);
        $this->assertStringNotContainsString('พยายามส่งแผนกอื่น', $this->feedHtml($this->member('user', 'HR')));

        // แก้ไขพร้อมส่งแผนกปลอม — แผนกเป้าหมายไม่เปลี่ยน
        $this->actingAs($author)->withHeaders($this->ajax())->patch(route('social.posts.update', $post), [
            'body' => 'แก้ไข', 'visibility' => 'department', 'target_department_id' => $hr->id,
        ])->assertOk();
        $this->assertSame($author->department_id, $post->fresh()->target_department_id);

        $this->actingAs($author)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['body' => 'x', 'visibility' => 'hr-only'])
            ->assertUnprocessable()->assertJsonValidationErrors(['visibility' => 'ระดับการมองเห็นโพสต์ไม่ถูกต้อง']);
    }

    public function test_an_account_without_a_department_cannot_choose_department_visibility(): void
    {
        $loner = $this->member('user', null);

        $this->actingAs($loner)->withHeaders($this->ajax())
            ->post(route('social.posts.store'), ['body' => 'อยากโพสต์เฉพาะแผนก', 'visibility' => 'department'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visibility');

        // ไม่ถูกเปลี่ยนเป็น "ทุกคน" ให้เองเงียบ ๆ
        $this->assertSame(0, SocialPost::count());

        $this->flushHeaders();
        $page = $this->actingAs($loner)->get(route('social.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="department" data-social-audience-option\s+aria-describedby="socialAudienceDepartmentHint"\s+disabled/', $page);
        $this->assertStringContainsString('บัญชีของคุณยังไม่มีแผนก จึงเลือกตัวเลือกนี้ไม่ได้', $page);

        // ค่าตั้งต้นยังโพสต์ได้ตามปกติ
        $this->actingAs($loner)->withHeaders($this->ajax())->post(route('social.posts.store'), ['body' => 'ถึงทุกคน'])->assertCreated();
        $this->assertSame('everyone', SocialPost::query()->value('visibility'));
    }

    public function test_the_composer_names_the_real_department_of_the_viewer(): void
    {
        $page = $this->actingAs($this->member('user', 'Logistics'))->get(route('social.index'))->assertOk();

        $page->assertSee('เฉพาะแผนกของฉัน (Logistics)')
            ->assertSee('เฉพาะพนักงานแผนก Logistics เท่านั้นที่เห็น')
            ->assertSee('name="visibility" value="everyone" checked', false);
    }

    public function test_changing_visibility_updates_who_can_see_it_and_keeps_comments_and_likes(): void
    {
        $author = $this->member('user', 'IT');
        $outsider = $this->member('user', 'HR');
        $post = $this->createPost($author, 'เปลี่ยนผู้ชมได้');
        $comment = $this->createComment($post, $outsider, 'ความเห็นจาก HR');
        $this->actingAs($outsider)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))->assertOk();

        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'เปลี่ยนผู้ชมได้', 'visibility' => 'department'])
            ->assertOk();

        $post->refresh();
        $this->assertSame(['department', $author->department_id], [$post->visibility, $post->target_department_id]);
        $this->assertStringNotContainsString('เปลี่ยนผู้ชมได้', $this->feedHtml($outsider));
        // เคยแสดงความคิดเห็นไว้ก็เข้าไม่ได้แล้ว รวมถึงแก้ไขความคิดเห็นของตัวเอง
        $this->actingAs($outsider)->getJson(route('social.posts.comments.index', $post))->assertNotFound();
        $this->actingAs($outsider)->withHeaders($this->ajax())
            ->patch(route('social.comments.update', $comment), ['body' => 'แก้'])->assertNotFound();
        $this->assertSame(1, $post->comments()->count());
        $this->assertSame(1, $post->likes()->count());

        $this->assertTrue(ActivityLog::query()
            ->where('subject_type', SocialPost::class)->where('subject_id', $post->id)
            ->where('action', 'updated')->where('description', 'like', '%everyone → department%')->exists());

        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'เปลี่ยนผู้ชมได้', 'visibility' => 'everyone'])
            ->assertOk();

        $this->assertSame([null, 'everyone'], [$post->fresh()->target_department_id, $post->fresh()->visibility]);
        $this->assertStringContainsString('เปลี่ยนผู้ชมได้', $this->feedHtml($outsider));
        $this->actingAs($outsider)->getJson(route('social.posts.comments.index', $post))->assertOk()->assertJsonPath('comments_count', 1);
    }

    public function test_an_edit_without_visibility_keeps_the_current_audience(): void
    {
        $author = $this->member('user', 'IT');
        $post = $this->departmentPost($author);

        $this->actingAs($author)->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'แก้ข้อความอย่างเดียว'])->assertOk();

        $this->assertSame('department', $post->fresh()->visibility);
        $this->assertFalse(ActivityLog::query()->where('subject_type', SocialPost::class)->where('action', 'updated')->exists());
    }

    public function test_moving_the_author_to_another_department_does_not_move_or_leak_the_post(): void
    {
        $author = $this->member('user', 'IT');
        $oldTeammate = $this->member('user', 'IT');
        $newTeammate = $this->member('user', 'HR');
        $post = $this->departmentPost($author, 'ข้อมูลของทีมเก่า');
        $itId = $author->department_id;

        $author->forceFill(['department_id' => $newTeammate->department_id])->save();

        $this->assertStringContainsString('ข้อมูลของทีมเก่า', $this->feedHtml($oldTeammate));
        $this->assertStringNotContainsString('ข้อมูลของทีมเก่า', $this->feedHtml($newTeammate));
        // เจ้าของยังเห็นและจัดการโพสต์ของตัวเองได้
        $this->assertStringContainsString('ข้อมูลของทีมเก่า', $this->feedHtml($author->fresh()));

        $this->actingAs($author->fresh())->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'แก้หลังย้ายแผนก', 'visibility' => 'department'])->assertOk();
        $this->assertSame($itId, $post->fresh()->target_department_id, 'แผนกที่ตรึงไว้ไม่เปลี่ยนตามผู้โพสต์');

        // เปลี่ยนเป็นทุกคนแล้วกลับมาเฉพาะแผนก → ใช้แผนกปัจจุบันของเจ้าของ
        $this->actingAs($author->fresh())->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'x', 'visibility' => 'everyone'])->assertOk();
        $this->actingAs($author->fresh())->withHeaders($this->ajax())
            ->patch(route('social.posts.update', $post), ['body' => 'x', 'visibility' => 'department'])->assertOk();
        $this->assertSame($newTeammate->department_id, $post->fresh()->target_department_id);
        $this->assertStringNotContainsString('data-post-id="'.$post->id.'"', $this->feedHtml($oldTeammate));
    }

    public function test_existing_posts_default_to_everyone(): void
    {
        $author = $this->member('user', 'IT');
        $id = DB::table('social_posts')->insertGetId([
            'user_id' => $author->id, 'body' => 'โพสต์ก่อนมีระดับการมองเห็น', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('everyone', DB::table('social_posts')->where('id', $id)->value('visibility'));
        $this->assertNull(DB::table('social_posts')->where('id', $id)->value('target_department_id'));
        $this->assertStringContainsString('โพสต์ก่อนมีระดับการมองเห็น', $this->feedHtml($this->member('user', 'HR')));
    }

    public function test_search_and_notifications_never_expose_a_department_post(): void
    {
        $this->departmentPost($this->member('user', 'IT'), 'คำลับเฉพาะZQX');
        $notifications = SystemNotification::count();
        $outsider = $this->member('user', 'HR');

        $search = $this->actingAs($outsider)->getJson(route('search', ['q' => 'คำลับเฉพาะZQX']))->assertOk();
        $this->assertStringNotContainsString('คำลับเฉพาะZQX', (string) $search->getContent());
        $this->assertSame($notifications, SystemNotification::count());
    }

    public function test_the_post_card_shows_its_audience(): void
    {
        $author = $this->member('user', 'IT');
        $this->createPost($author, 'ถึงทุกคน');
        $this->departmentPost($author, 'ถึงไอที');

        $html = $this->feedHtml($author);
        $this->assertStringContainsString('title="ทุกคนในองค์กร" data-social-post-audience="everyone"', $html);
        $this->assertStringContainsString('title="เฉพาะแผนก IT" data-social-post-audience="department"', $html);
        $this->assertStringContainsString('ผู้ที่มองเห็น: เฉพาะแผนก IT', $html);
        $this->assertStringContainsString('&quot;visibility&quot;:&quot;department&quot;', $html);
    }

    public function test_a_department_with_department_posts_cannot_be_deleted(): void
    {
        $author = $this->member('user', 'Temp');
        $post = $this->departmentPost($author);
        $department = Department::findOrFail($author->department_id);
        $author->forceFill(['department_id' => null])->save();

        $this->actingAs($this->member('admin', null))->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertSame($department->id, $post->fresh()->target_department_id);
    }

    public function test_rolling_back_the_migration_refuses_while_department_posts_exist(): void
    {
        $this->departmentPost($this->member('user', 'IT'));
        $migration = require database_path('migrations/2026_11_23_000001_add_visibility_and_target_department_to_social_posts_table.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
