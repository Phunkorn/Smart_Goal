<?php

namespace App\Policies;

use App\Models\SocialComment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * สิทธิ์ของความคิดเห็นในชุมชน
 *
 * - ความคิดเห็นของโพสต์ที่ผู้ใช้มองไม่เห็น (ถูกลบ หรือจำกัดเฉพาะแผนกอื่น) ตอบ 404
 *   รวมถึงความคิดเห็นของตัวเอง เมื่อโพสต์ถูกเปลี่ยนเป็นเฉพาะแผนกที่ตัวเองไม่ได้อยู่
 * - แก้ไขได้เฉพาะเจ้าของความคิดเห็น ไม่มี admin bypass
 * - ลบได้โดยเจ้าของ และ admin เพื่อดูแลเนื้อหา (moderation) เฉพาะโพสต์ที่ admin มองเห็น
 */
class SocialCommentPolicy
{
    public function update(User $user, SocialComment $comment): Response
    {
        $visible = $this->visible($user, $comment);
        if ($visible->denied()) {
            return $visible;
        }

        return (int) $comment->user_id === (int) $user->id ? Response::allow() : Response::deny();
    }

    public function delete(User $user, SocialComment $comment): Response
    {
        $visible = $this->visible($user, $comment);
        if ($visible->denied()) {
            return $visible;
        }

        return (int) $comment->user_id === (int) $user->id || $user->role === 'admin'
            ? Response::allow()
            : Response::deny();
    }

    private function visible(User $user, SocialComment $comment): Response
    {
        // post() ใช้ global scope ของ SoftDeletes — โพสต์ที่ถูกลบคืน null
        $post = $comment->post;

        return ! $comment->trashed() && $post !== null && $post->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
