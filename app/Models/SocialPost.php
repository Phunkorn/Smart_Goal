<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * โพสต์ในชุมชน (Social Community)
 *
 * การมองเห็น (ที่เดียวของกติกานี้คือ scopeVisibleTo() คู่กับ isVisibleTo() ด้านล่าง)
 *   everyone    ทุกบัญชีที่เข้าสู่ระบบ ทุกบทบาท ทุกแผนก (ค่าตั้งต้น และค่าของโพสต์เดิมทั้งหมด)
 *   department  เฉพาะบัญชีที่ department_id ตรงกับ target_department_id ไม่จำกัดบทบาท
 *               admin ต่างแผนกไม่เห็น — บทบาทไม่ใช่เหตุผลให้เห็นโพสต์ของแผนกอื่น
 *   เจ้าของโพสต์เห็นโพสต์ของตัวเองเสมอ แม้ย้ายแผนกไปแล้ว
 *
 * user_id, visibility และ target_department_id ไม่อยู่ใน $fillable โดยตั้งใจ — ผู้โพสต์มาจาก
 * ผู้ใช้ที่ล็อกอิน และแผนกเป้าหมายมาจากแผนกจริงของผู้โพสต์ฝั่ง server (SocialPostService)
 * ไม่มีทางส่ง user_id หรือ department id ของคนอื่นมากับฟอร์ม
 *
 * การลบเป็น soft delete และเขียนลงถังขยะ (TrashRestorers) รูปภาพยังอยู่บนดิสก์
 * จนกว่าจะถูกลบถาวร จึงกู้คืนโพสต์ได้ครบทั้งรูป
 */
class SocialPost extends Model
{
    use SoftDeletes;

    public const VISIBILITY_EVERYONE = 'everyone';

    public const VISIBILITY_DEPARTMENT = 'department';

    /** @var list<string> */
    public const VISIBILITIES = [self::VISIBILITY_EVERYONE, self::VISIBILITY_DEPARTMENT];

    protected $attributes = [
        'visibility' => self::VISIBILITY_EVERYONE,
    ];

    protected $fillable = [
        'body',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'target_department_id' => 'integer',
        ];
    }

    /**
     * โพสต์ที่ผู้ใช้คนนี้มีสิทธิ์เห็น — ทุกคิวรีที่ดึงโพสต์ไปแสดงต้องผ่าน scope นี้
     * ผู้ใช้ที่ไม่มีแผนกเห็นเฉพาะโพสต์ everyone และโพสต์ของตัวเอง (null ไม่เคยตรงกับแผนกใด)
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('visibility', self::VISIBILITY_EVERYONE)
                ->orWhere('user_id', $user->id);

            if ($user->department_id !== null) {
                $visible->orWhere(fn (Builder $department) => $department
                    ->where('visibility', self::VISIBILITY_DEPARTMENT)
                    ->where('target_department_id', $user->department_id));
            }
        });
    }

    /** กติกาเดียวกับ scopeVisibleTo() สำหรับโพสต์ที่โหลดมาแล้ว (route model binding, ไฟล์รูป, policy) */
    public function isVisibleTo(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return match ($this->visibility) {
            self::VISIBILITY_EVERYONE => true,
            self::VISIBILITY_DEPARTMENT => $user->department_id !== null
                && $this->target_department_id !== null
                && (int) $this->target_department_id === (int) $user->department_id,
            default => false,
        };
    }

    public function isDepartmentOnly(): bool
    {
        return $this->visibility === self::VISIBILITY_DEPARTMENT;
    }

    public function targetDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'target_department_id');
    }

    /** รวมบัญชีที่ถูกลบแล้ว โพสต์เก่าจึงยังบอกได้ว่าใครเป็นผู้เขียน */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function media(): HasMany
    {
        return $this->hasMany(SocialPostMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SocialComment::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(SocialPostLike::class);
    }

    public function hasBody(): bool
    {
        return trim((string) $this->body) !== '';
    }
}
