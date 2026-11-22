<?php

namespace App\Policies;

use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * สิทธิ์ของโพสต์ในชุมชน (Social Community)
 *
 * กติกาที่เจ้าของระบบกำหนด
 * ---------------------------------------------------------------
 * - ทุกบัญชีที่เข้าสู่ระบบ (admin, user, viewer) เปิดชุมชน โพสต์ แสดงความคิดเห็น และกดถูกใจได้
 *   (viewer ซึ่งเป็น read-only ในงาน/ประชุม ได้รับอนุญาตที่นี่ตามข้อกำหนดของฟีเจอร์นี้
 *   ถ้าต้องการปิดให้ viewer โพสต์ ให้แก้ที่ create()/interact() ที่เดียว)
 * - ใครเห็นโพสต์ไหนมาจาก SocialPost::isVisibleTo() / scopeVisibleTo() ที่เดียว
 *   (ทุกคนในองค์กร หรือเฉพาะแผนกเป้าหมาย เจ้าของเห็นของตัวเองเสมอ)
 * - โพสต์ที่มองไม่เห็นตอบ 404 ทุก action (เปิด รูป ความคิดเห็น ถูกใจ แก้ไข ลบ)
 *   ไม่ใช่ 403 — ผู้ที่ไม่มีสิทธิ์ต้องไม่รู้ด้วยซ้ำว่าโพสต์นั้นมีอยู่
 * - แก้ไขได้เฉพาะเจ้าของโพสต์ ไม่มี admin bypass
 * - ลบได้โดยเจ้าของ และ admin เพื่อดูแลเนื้อหา (moderation) เฉพาะโพสต์ที่ admin คนนั้นมองเห็น
 *   — การเป็น admin ไม่ใช่เหตุผลให้เห็นหรือจัดการโพสต์ที่จำกัดเฉพาะแผนกอื่น
 * - โพสต์ที่ถูกลบแล้วรับความคิดเห็นและการถูกใจไม่ได้
 *
 * การตรวจบัญชีปิดใช้งานและการเปลี่ยนรหัสผ่านครั้งแรกอยู่ที่ middleware ของกลุ่ม route
 */
class SocialPostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialPost $post): Response
    {
        return $this->visible($user, $post);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SocialPost $post): Response
    {
        $visible = $this->visible($user, $post);
        if ($visible->denied()) {
            return $visible;
        }

        return $this->owns($user, $post) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, SocialPost $post): Response
    {
        $visible = $this->visible($user, $post);
        if ($visible->denied()) {
            return $visible;
        }

        return $this->owns($user, $post) || $user->role === 'admin' ? Response::allow() : Response::deny();
    }

    /** แสดงความคิดเห็นและกดถูกใจ */
    public function interact(User $user, SocialPost $post): Response
    {
        return $this->visible($user, $post);
    }

    private function visible(User $user, SocialPost $post): Response
    {
        return ! $post->trashed() && $post->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function owns(User $user, SocialPost $post): bool
    {
        return (int) $post->user_id === (int) $user->id;
    }
}
