<?php

namespace App\Services;

use App\Models\SocialFeedRead;
use App\Models\SocialPost;
use App\Models\User;

/**
 * จำนวน "โพสต์ใหม่" บนเมนูชุมชน
 *
 * โพสต์ใหม่ = โพสต์ที่ผู้ใช้มีสิทธิ์เห็น (SocialPost::scopeVisibleTo — กติกาการมองเห็นชุดเดียวกับฟีด)
 * ไม่ใช่ของตัวเอง และสร้างหลังจากครั้งล่าสุดที่ผู้ใช้เปิดหน้าชุมชน
 * โพสต์เฉพาะแผนกอื่นจึงไม่ถูกนับ และตัวเลขบนเมนูไม่บอกใบ้ว่ามีโพสต์ที่ผู้ใช้ไม่มีสิทธิ์เห็น
 *
 * ผู้ใช้ที่ยังไม่เคยเปิดฟีด (ไม่มีแถว) นับเฉพาะโพสต์ย้อนหลังไม่เกิน social.unread_lookback_days วัน
 * ตัวเลขนับสูงสุด DISPLAY_CAP รายการ เมนูแสดง 99+ เมื่อเกิน จึงไม่ต้องนับทั้งตาราง
 */
class SocialFeedReadService
{
    public const DISPLAY_CAP = 100;

    public function newPostCount(User $user): int
    {
        /*
         * ยังไม่เคยเปิดฟีด (ไม่มีแถว) → นับย้อนหลังไม่เกิน unread_lookback_days วัน
         *
         * เดิมคืน 0 ทันที ซึ่งทำให้ป้ายไม่ขึ้นเลยบน production: หลัง deploy ไม่มีผู้ใช้คนใดมีแถวนี้
         * บัญชีที่ยังไม่เคยเข้าหน้าชุมชนจึงไม่เคยเห็นโพสต์ใหม่ของคนอื่น ช่วงย้อนหลังที่จำกัดไว้ยังกัน
         * ไม่ให้ประวัติทั้งหมดถูกนับเป็นของใหม่ (และตัวเลขถูกตัดที่ DISPLAY_CAP อยู่แล้ว)
         */
        $seenAt = SocialFeedRead::query()->whereKey($user->id)->value('seen_at')
            ?? now()->subDays(max(1, (int) config('social.unread_lookback_days', 7)));

        return SocialPost::query()
            ->visibleTo($user)
            ->where('user_id', '!=', $user->id)
            ->where('created_at', '>', $seenAt)
            ->limit(self::DISPLAY_CAP)
            ->pluck('id')
            ->count();
    }

    /**
     * บันทึกว่าผู้ใช้เปิดฟีดแล้ว — ต้องเรียก "ก่อน" ดึงโพสต์มาแสดง
     * โพสต์ที่เกิดระหว่างบันทึกกับดึงจึงถูกแสดงในฟีดนี้เลย ส่วนโพสต์ที่เกิดหลังดึงยังนับเป็นของใหม่
     * (ถ้าบันทึกหลังดึง โพสต์ที่เกิดคั่นกลางจะไม่ถูกแสดงและไม่ถูกนับ)
     */
    public function markSeen(User $user): void
    {
        SocialFeedRead::query()->upsert(
            [['user_id' => $user->id, 'seen_at' => now()]],
            ['user_id'],
            ['seen_at'],
        );
    }
}
