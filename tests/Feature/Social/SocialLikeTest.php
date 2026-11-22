<?php

namespace Tests\Feature\Social;

use App\Models\SocialPostLike;
use Illuminate\Database\QueryException;

/**
 * ถูกใจ / ยกเลิกถูกใจ — หนึ่งคนหนึ่งแถวต่อโพสต์
 */
class SocialLikeTest extends SocialFeatureCase
{
    public function test_like_and_unlike_are_idempotent(): void
    {
        $post = $this->createPost($this->member());
        $fan = $this->member('user', 'HR');

        $this->actingAs($fan)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))
            ->assertOk()->assertJsonPath('liked', true)->assertJsonPath('likes_count', 1);
        // ส่งซ้ำ (กดรัว / สองแท็บ) — ยังเป็นแถวเดียว
        $this->actingAs($fan)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))
            ->assertOk()->assertJsonPath('likes_count', 1);
        $this->assertSame(1, SocialPostLike::count());

        $this->actingAs($fan)->withHeaders($this->ajax())->delete(route('social.posts.like.destroy', $post))
            ->assertOk()->assertJsonPath('liked', false)->assertJsonPath('likes_count', 0);
        $this->actingAs($fan)->withHeaders($this->ajax())->delete(route('social.posts.like.destroy', $post))
            ->assertOk()->assertJsonPath('likes_count', 0);
        $this->assertSame(0, SocialPostLike::count());
    }

    public function test_the_database_refuses_a_second_like_row_for_the_same_user(): void
    {
        $post = $this->createPost($this->member());
        $fan = $this->member('user', 'HR');
        $row = ['social_post_id' => $post->id, 'user_id' => $fan->id, 'created_at' => now()];

        SocialPostLike::query()->insert($row);

        $this->expectException(QueryException::class);
        SocialPostLike::query()->insert($row);
    }

    public function test_like_counts_and_the_viewer_state_are_correct_per_user(): void
    {
        $post = $this->createPost($this->member());
        $fans = collect(range(1, 3))->map(fn (int $i) => $this->member('user', 'Dept '.$i));

        foreach ($fans as $index => $fan) {
            $this->actingAs($fan)->withHeaders($this->ajax())->put(route('social.posts.like.store', $post))
                ->assertJsonPath('likes_count', $index + 1);
        }

        $this->actingAs($fans[0])->get(route('social.index'))->assertOk()
            ->assertSee('<span data-social-like-count>3</span> ถูกใจ', false)
            ->assertSee('aria-pressed="true"', false);

        $this->actingAs($this->member('user', 'Other'))->get(route('social.index'))->assertOk()
            ->assertSee('<span data-social-like-count>3</span> ถูกใจ', false)
            ->assertDontSee('aria-pressed="true"', false);
    }

    public function test_a_deleted_post_cannot_be_liked(): void
    {
        $post = $this->createPost($this->member());
        $post->delete();

        $this->actingAs($this->member('user', 'HR'))->withHeaders($this->ajax())
            ->put(route('social.posts.like.store', $post))->assertNotFound();
        $this->assertSame(0, SocialPostLike::count());
    }
}
