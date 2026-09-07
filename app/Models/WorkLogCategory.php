<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * หมวดงานของบันทึกงานประจำวัน (IT Support, ซ่อมบำรุง ฯลฯ)
 *
 * เป็นตาราง lookup ที่ผู้ดูแลระบบเพิ่มเองได้ ไม่ใช่ค่าคงที่ในโค้ด
 * ต่างจาก "ประเภทงาน" (kind) ที่เป็นชุดปิดตายอยู่ใน App\Support\WorkLogDesign
 */
class WorkLogCategory extends Model
{
    protected $fillable = [
        'name',
        'tone',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WorkLogTemplate::class);
    }

    /**
     * ตัวเลือกที่ให้ผู้ใช้เลือกได้ในฟอร์ม เรียงตามลำดับที่ผู้ดูแลกำหนด
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
