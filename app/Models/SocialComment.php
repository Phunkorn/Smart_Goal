<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ความคิดเห็นใต้โพสต์ในชุมชน — ตอบกลับได้ลึกหนึ่งระดับ
 *
 * parent_id เป็น null คือความคิดเห็นหลัก มีค่าคือการตอบกลับความคิดเห็นหลักนั้น
 * การตอบกลับ "การตอบกลับ" ถูกผูกเข้ากับความคิดเห็นหลักตัวเดียวกันเสมอ
 * (App\Services\SocialCommentService) โครงสร้างจึงไม่ลึกเกินหนึ่งระดับ
 *
 * user_id, social_post_id และ parent_id ไม่อยู่ใน $fillable — กำหนดจากผู้ใช้ที่ล็อกอิน
 * และโพสต์ใน URL เท่านั้น
 */
class SocialComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'body',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }
}
