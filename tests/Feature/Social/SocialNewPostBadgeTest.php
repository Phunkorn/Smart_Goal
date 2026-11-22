<?php

namespace Tests\Feature\Social;

use App\Models\SocialFeedRead;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\SocialFeedReadService;

/**
 * ป้ายจำนวนโพสต์ใหม่บนเมนูชุมชน — จำนวน กติกาการมองเห็น และตำแหน่งของป้าย (ซ้อนมุมขวาบนของไอคอน)
 */
class SocialNewPostBadgeTest extends SocialFeatureCase
{
    private function seenAnHourAgo(User $user): void
    {
        SocialFeedRead::query()->create(['user_id' => $user->id, 'seen_at' => now()->subHour()]);
    }

    private function newPosts(User $user): int
    {
        return app(SocialFeedReadService::class)->newPostCount($user);
    }

    /** @return array{desktop: string, mobile: string} ลิงก์ "ชุมชน" ในเมนูทั้งสองแบบ */
    private function socialLinks(User $user): array
    {
        $content = $this->actingAs($user)->get(route('settings.index'))->assertOk()->getContent();
        $pattern = '/<a href="'.preg_quote(route('social.index'), '/').'" class="(?:top-nav__link|mobile-nav__link)[^"]*"[^>]*>(.*?)<\/a>/s';
        preg_match_all($pattern, $content, $matches);
        $this->assertCount(2, $matches[1], 'ต้องมีลิงก์ชุมชนทั้งเมนูแนวนอนและแถบข้าง');

        return ['desktop' => $matches[1][0], 'mobile' => $matches[1][1]];
    }

    public function test_only_posts_the_user_can_see_from_other_people_after_the_last_visit_are_counted(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);

        $this->createPost($this->member('user', 'HR'), 'ใหม่จาก HR');
        $this->createPost($this->member('user', 'IT'), 'ใหม่ทีมเดียวกัน');
        $this->createPost($this->member('user', 'HR'), 'เฉพาะ HR', ['visibility' => 'department', 'target_department_id' => $this->member('user', 'HR')->department_id]);
        $itOnly = $this->member('user', 'IT');
        $this->createPost($itOnly, 'เฉพาะ IT', ['visibility' => 'department', 'target_department_id' => $itOnly->department_id]);
        $this->createPost($me, 'ของฉันเอง');
        $this->createPost($this->member('user', 'HR'), 'เก่ากว่าที่เห็นล่าสุด', ['created_at' => now()->subHours(2)]);
        $trashed = $this->createPost($this->member('user', 'HR'), 'ถูกลบ');
        $trashed->delete();

        // นับ: ใหม่จาก HR, ใหม่ทีมเดียวกัน, เฉพาะ IT (แผนกเดียวกับฉัน) — ไม่นับ เฉพาะ HR / ของตัวเอง / เก่า / ถูกลบ
        $this->assertSame(3, $this->newPosts($me));
    }

    public function test_an_admin_in_another_department_is_not_told_about_department_only_posts(): void
    {
        $admin = $this->member('admin', 'Management');
        $this->seenAnHourAgo($admin);
        $author = $this->member('user', 'IT');
        $this->createPost($author, 'ลับ', ['visibility' => 'department', 'target_department_id' => $author->department_id]);

        $this->assertSame(0, $this->newPosts($admin));
        $this->assertStringNotContainsString('top-nav__badge', $this->socialLinks($admin)['desktop']);
    }

    /**
     * บั๊กจริงบน production (commit dd7daf9): หลัง deploy ไม่มีใครมีแถวใน social_feed_reads
     * เดิมผู้ที่ยังไม่เคยเปิดฟีดได้ 0 เสมอ ป้ายจึงไม่ถูกวาดเลยแม้มีโพสต์ใหม่
     * ตอนนี้นับโพสต์ย้อนหลังไม่เกิน social.unread_lookback_days วัน — ไม่ท่วมด้วยประวัติทั้งหมด
     */
    public function test_a_user_who_never_opened_the_feed_counts_recent_posts_but_not_old_history(): void
    {
        config(['social.unread_lookback_days' => 7]);
        $me = $this->member('user', 'IT');
        $this->createPost($this->member('user', 'HR'), 'เมื่อวาน', ['created_at' => now()->subDay()]);
        $this->createPost($this->member('user', 'HR'), 'เดือนก่อน', ['created_at' => now()->subDays(30)]);

        $this->assertSame(1, $this->newPosts($me));
    }

    /**
     * ขั้นตอนทดสอบจริงของเจ้าของระบบ ผ่าน HTTP ทั้งหมด (ไม่เขียนตาราง social_feed_reads เอง):
     * A โพสต์ → B เห็นป้าย → B เข้าชุมชน ป้ายหาย → A โพสต์อีก → B เห็นป้ายใหม่ · และโพสต์เฉพาะแผนกอื่นไม่ถูกนับ
     */
    public function test_the_owner_scenario_end_to_end_with_cross_department_visibility(): void
    {
        $a = $this->member('user', 'IT');
        $b = $this->member('user', 'IT');
        $hr = $this->member('user', 'HR');
        $badge = fn (User $user): ?string => preg_match('/top-nav__badge--icon"[^>]*>([^<]+)<\/span>/', $this->socialLinks($user)['desktop'], $m) ? $m[1] : null;
        $post = fn (User $author, array $data) => $this->actingAs($author)->withHeaders($this->ajax())->post(route('social.posts.store'), $data)->assertCreated();

        $post($a, ['body' => 'โพสต์แรกของ A']);
        $this->flushHeaders();
        $this->assertSame('1', $badge($b), 'B ยังไม่เคยเปิดฟีด แต่ต้องเห็นโพสต์ใหม่ของ A');
        $this->assertNull($badge($a), 'ไม่นับโพสต์ของตัวเอง');

        // โพสต์เฉพาะแผนก HR — B (IT) ต้องไม่ถูกนับ
        $post($hr, ['body' => 'เฉพาะ HR', 'visibility' => 'department']);
        $this->flushHeaders();
        $this->assertSame('1', $badge($b));

        $this->actingAs($b)->get(route('social.index'))->assertOk();
        $this->assertNull($badge($b), 'เปิดชุมชนแล้วป้ายหาย');

        $this->travel(1)->minutes();
        $post($a, ['body' => 'โพสต์ที่สองของ A']);
        $post($a, ['body' => 'เฉพาะ IT', 'visibility' => 'department']);
        $this->flushHeaders();
        $this->assertSame('2', $badge($b), 'โพสต์ใหม่หลังเปิดฟีดนับอีกครั้ง รวมโพสต์เฉพาะแผนกของตัวเอง');
        $this->assertSame('2', preg_match('/top-nav__badge--icon"[^>]*>([^<]+)<\/span>/', $this->socialLinks($b)['mobile'], $m) ? $m[1] : null, 'แถบข้างมือถือเห็นตัวเลขเดียวกัน');
    }

    public function test_opening_the_feed_clears_the_count_and_later_posts_count_again(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);
        $this->createPost($this->member('user', 'HR'), 'ก่อนเปิดฟีด');
        $this->assertSame(1, $this->newPosts($me));
        $this->assertStringContainsString('>1</span>', $this->socialLinks($me)['desktop']);

        $this->actingAs($me)->get(route('social.index'))->assertOk()->assertSee('ก่อนเปิดฟีด');
        $this->assertSame(0, $this->newPosts($me));
        $this->assertStringNotContainsString('top-nav__badge', $this->socialLinks($me)['desktop']);

        $this->travel(5)->minutes();
        $this->createPost($this->member('user', 'HR'), 'หลังเปิดฟีด');
        $this->assertSame(1, $this->newPosts($me));
    }

    public function test_loading_more_posts_does_not_touch_the_unread_count(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);
        $this->createPost($this->member('user', 'HR'));

        $this->actingAs($me)->getJson(route('social.posts.index'))->assertOk();

        $this->assertSame(1, $this->newPosts($me));
    }

    public function test_the_badge_overlaps_the_icon_and_never_trails_the_label_on_desktop_and_mobile(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);
        foreach (range(1, 3) as $i) {
            $this->createPost($this->member('user', 'HR'), 'โพสต์ '.$i);
        }

        foreach (['desktop' => ['top-nav__icon-wrap', 'top-nav__icon', 'top-nav__label'], 'mobile' => ['mobile-nav__icon-wrap', 'mobile-nav__icon', 'mobile-nav__label']] as $where => [$wrap, $icon, $label]) {
            $link = $this->socialLinks($me)[$where];

            $this->assertMatchesRegularExpression(
                '/<span class="'.$wrap.'"><i class="bi bi-chat-square-text '.$icon.'" aria-hidden="true"><\/i>'
                .'<span class="top-nav__badge top-nav__badge--danger top-nav__badge--icon"\s+title="โพสต์ใหม่ 3 โพสต์" aria-label="โพสต์ใหม่ 3 โพสต์"\s*>3<\/span>\s*<\/span>\s*'
                .'<span class="'.$label.'">ชุมชน<\/span>/',
                $link,
                $where,
            );
            $this->assertSame(1, substr_count($link, 'top-nav__badge '), $where);
            $this->assertDoesNotMatchRegularExpression('/ชุมชน<\/span>\s*<span class="top-nav__badge/', $link, "{$where}: ป้ายต้องไม่ต่อท้ายข้อความ");
        }
    }

    public function test_more_than_ninety_nine_shows_99_plus(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);
        $author = $this->member('user', 'HR');
        foreach (range(1, 101) as $i) {
            $this->createPost($author, 'โพสต์ '.$i);
        }

        $this->assertSame(SocialFeedReadService::DISPLAY_CAP, $this->newPosts($me));
        $link = $this->socialLinks($me)['desktop'];
        $this->assertStringContainsString('>99+</span>', $link);
        $this->assertStringContainsString('aria-label="โพสต์ใหม่ มากกว่า 99 โพสต์"', $link);

        // 99 พอดียังแสดงเป็นตัวเลขจริง
        SocialPost::query()->orderByDesc('id')->limit(2)->get()->each->delete();
        $this->assertSame(99, $this->newPosts($me));
        $this->assertStringContainsString('>99</span>', $this->socialLinks($me)['desktop']);
    }

    public function test_no_badge_and_an_unchanged_icon_when_there_is_nothing_new(): void
    {
        $me = $this->member('user', 'IT');
        $this->seenAnHourAgo($me);

        foreach ($this->socialLinks($me) as $where => $link) {
            $this->assertStringNotContainsString('-icon-wrap', $link, $where);
            $this->assertStringNotContainsString('top-nav__badge', $link, $where);
            $this->assertStringContainsString('ชุมชน', $link, $where);
        }
    }

    public function test_every_role_gets_the_badge_but_other_menu_badges_keep_their_inline_style(): void
    {
        foreach (['admin' => null, 'user' => 'IT', 'viewer' => 'Audit'] as $role => $department) {
            $user = $this->member($role, $department);
            $this->seenAnHourAgo($user);
            $this->createPost($this->member('user', 'Other '.$role), 'ถึงทุกคน');

            $this->assertStringContainsString('top-nav__badge--icon', $this->socialLinks($user)['desktop'], $role);
        }

        // ป้ายการแจ้งเตือนยังเป็นแบบต่อท้ายข้อความ ไม่ถูกย้ายไปซ้อนไอคอน
        $content = $this->actingAs($this->member('user', 'IT'))->get(route('settings.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-notification-count data-nav-notification-count[^>]*>[^<]*<\/span><\/span>\s*<span class="top-nav__label">การแจ้งเตือน/', $content);
        $this->assertStringNotContainsString('top-nav__badge--icon" data-notification-count', $content);
    }
}
