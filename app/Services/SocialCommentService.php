<?php

namespace App\Services;

use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ความคิดเห็นในชุมชน — ตอบกลับได้หนึ่งระดับ
 *
 * ความคิดเห็นถูกโหลดเมื่อผู้ใช้เปิดส่วนความคิดเห็นของโพสต์นั้นเท่านั้น ฟีดไม่โหลดความคิดเห็นของทุกโพสต์
 * หน้าหนึ่งคือความคิดเห็นหลักล่าสุด N รายการ (เรียงเก่า → ใหม่เพื่อให้อ่านต่อเนื่อง) พร้อมการตอบกลับ
 * ทั้งหมดของความคิดเห็นหลักเหล่านั้น "ดูความคิดเห็นก่อนหน้า" ใช้ id เป็นเคอร์เซอร์ (id < before)
 */
class SocialCommentService
{
    /**
     * @return array{comments: Collection<int, SocialComment>, next_cursor: int|null}
     */
    public function page(SocialPost $post, ?int $before = null, ?int $limit = null): array
    {
        $limit ??= (int) config('social.comment_page_size', 10);

        $comments = SocialComment::query()
            ->where('social_post_id', $post->id)
            ->whereNull('parent_id')
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->with(['author.department', 'replies.author.department'])
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $comments->count() > $limit;
        $comments = $comments->take($limit);

        // SocialCommentPolicy อ่านโพสต์แม่ของทุกความคิดเห็น — ผูกโพสต์ที่มีอยู่แล้วไว้ กันคิวรีทีละรายการ
        $comments->each(function (SocialComment $comment) use ($post): void {
            $comment->setRelation('post', $post);
            $comment->replies->each->setRelation('post', $post);
        });

        return [
            'comments' => $comments->reverse()->values(),
            'next_cursor' => $hasMore ? (int) $comments->last()->id : null,
        ];
    }

    public function create(SocialPost $post, User $author, string $body, ?int $parentId = null): SocialComment
    {
        $parent = $parentId !== null ? $this->rootParent($post, $parentId) : null;

        $comment = new SocialComment(['body' => $body]);
        $comment->social_post_id = $post->id;
        $comment->user_id = $author->id;
        $comment->parent_id = $parent?->id;
        $comment->save();

        return $comment->setRelation('post', $post)->load(['author.department', 'replies']);
    }

    public function update(SocialComment $comment, string $body): SocialComment
    {
        if ($body !== $comment->body) {
            $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();
        }

        return $comment->load(['author.department', 'replies.author.department']);
    }

    /** ลบความคิดเห็นหลักพาการตอบกลับทั้งหมดไปด้วย จำนวนความคิดเห็นของโพสต์จึงตรงกับที่แสดง */
    public function delete(SocialComment $comment, User $actor): void
    {
        DB::transaction(function () use ($comment, $actor): void {
            if ((int) $comment->user_id !== (int) $actor->id) {
                $comment->loadMissing('author');
                AuditTrail::log('deleted', $comment, 'ผู้ดูแลระบบลบความคิดเห็นในชุมชนของ '.($comment->author?->name ?? 'ไม่ระบุ'));
            }

            if (! $comment->isReply()) {
                $comment->replies()->get()->each->delete();
            }

            $comment->delete();
        });
    }

    public function countFor(SocialPost $post): int
    {
        return $post->comments()->count();
    }

    /** ตอบกลับการตอบกลับ → ผูกกับความคิดเห็นหลักตัวเดียวกัน (ลึกไม่เกินหนึ่งระดับ) */
    private function rootParent(SocialPost $post, int $parentId): SocialComment
    {
        $parent = SocialComment::query()
            ->where('social_post_id', $post->id)
            ->whereKey($parentId)
            ->first();

        if ($parent && $parent->parent_id !== null) {
            $parent = SocialComment::query()->where('social_post_id', $post->id)->whereKey($parent->parent_id)->first();
        }

        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'ความคิดเห็นที่ต้องการตอบกลับถูกลบไปแล้ว']);
        }

        return $parent;
    }
}
