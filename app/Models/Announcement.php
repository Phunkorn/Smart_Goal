<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ประกาศของหัวหน้าแผนก
 *
 * created_by และ department_id ไม่อยู่ใน $fillable โดยตั้งใจ ค่าทั้งสองถูกกำหนดจาก
 * ผู้ใช้ที่ล็อกอินอยู่ใน App\Services\AnnouncementService เท่านั้น เพื่อไม่ให้ใคร
 * ส่ง department_id ของแผนกอื่นมากับฟอร์มแล้วประกาศในนามแผนกนั้นได้
 *
 * ใครเห็นประกาศไหนมีแหล่งเดียวคือ App\Services\AnnouncementQueryService
 * ใครแก้/ลบได้มีแหล่งเดียวคือ App\Policies\AnnouncementPolicy
 */
class Announcement extends Model
{
    use SoftDeletes;

    public const AUDIENCE_DEPARTMENT = 'department';

    public const AUDIENCE_ALL = 'all';

    protected $fillable = [
        'title',
        'body',
        'audience',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * ผู้ประกาศ — รวมบัญชีที่ถูกลบแล้ว เพราะประกาศที่ยังอยู่ในช่วงแสดงต้องบอกได้เสมอ
     * ว่าใครเป็นคนประกาศ ไม่ใช่กลายเป็นการ์ดที่ไม่มีชื่อ
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isForAllDepartments(): bool
    {
        return $this->audience === self::AUDIENCE_ALL;
    }
}
