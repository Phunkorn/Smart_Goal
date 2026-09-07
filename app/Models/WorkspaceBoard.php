<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * กระดานไอเดียหนึ่งใบ - พื้นที่วาดเปล่าที่คนในแผนกเดียวกันช่วยกันแก้ได้
 *
 * ตั้งใจไม่มี scope สำหรับสิทธิ์การมองเห็นอยู่ในโมเดล ด้วยเหตุผลเดียวกับ WorkLog
 * คือให้กติกาว่าใครเห็นอะไรมีแหล่งเดียว ได้แก่ App\Policies\WorkspaceBoardPolicy
 * (สำหรับกระดานใบเดียว) และ App\Services\WorkspaceBoardQueryService (สำหรับ
 * query ระดับ SQL) การใส่ scope ไว้ที่นี่ด้วยจะกลายเป็นแหล่งความจริงที่สาม
 *
 * element_count, last_edited_by และ last_edited_at เป็นค่าที่สรุปมาจากเนื้อหา
 * ของกระดาน ต้องเขียนผ่าน App\Services\WorkspaceBoardDocumentService เท่านั้น
 * จึงไม่อยู่ใน $fillable เพื่อกันการผูกค่าจาก request โดยตรง
 */
class WorkspaceBoard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'created_by',
        'title',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'element_count' => 'integer',
            'last_edited_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function document(): HasOne
    {
        return $this->hasOne(WorkspaceBoardDocument::class, 'workspace_board_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkspaceBoardAttachment::class, 'workspace_board_id');
    }

    /**
     * กระดานนี้เปิดให้แผนกอื่นเข้ามาดูได้หรือไม่
     *
     * ใช้แทนการเทียบสตริง 'organization' กระจายตามที่ต่าง ๆ เพื่อให้เวลาจะเพิ่ม
     * ระดับการมองเห็นในอนาคต มีจุดเดียวที่ต้องแก้
     */
    public function isVisibleToOtherDepartments(): bool
    {
        return $this->visibility === 'organization';
    }
}
