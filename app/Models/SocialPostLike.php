<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การถูกใจโพสต์ — หนึ่งคนถูกใจโพสต์หนึ่งได้หนึ่งแถว
 *
 * unique (social_post_id, user_id) ในฐานข้อมูลเป็นตัวกันจริง ไม่ใช่การเช็กก่อนเขียน
 * App\Services\SocialPostService::like() ใช้ insertOrIgnore จึงกดพร้อมกันกี่ครั้งก็ได้แถวเดียว
 */
class SocialPostLike extends Model
{
    public const UPDATED_AT = null;

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
