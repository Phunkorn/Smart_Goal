<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * โต๊ะคอมพิวเตอร์หนึ่งตัวบนผังห้อง Call Center
 *
 * ตารางนี้คือแหล่งความจริงเดียวของตำแหน่งโต๊ะ ทั้งผัง SVG บนเว็บและผังในแบบตรวจ
 * A4 อ่านจากที่นี่ชุดเดียว เพื่อไม่ให้ต้องแก้ตำแหน่งสองรอบเมื่อห้องจริงย้ายโต๊ะ
 *
 * พิกัดเป็นหน่วยของ viewBox ตาม ComputerCheckDesign::ROOM_WIDTH/ROOM_HEIGHT
 * ไม่ใช่พิกเซลบนจอ การย่อ/ขยายผังจึงไม่ทำให้รูปทรงห้องเปลี่ยน
 */
class ComputerCheckWorkstation extends Model
{
    protected $fillable = [
        'code',
        'zone',
        'position_x',
        'position_y',
        'width',
        'height',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'position_x' => 'integer',
            'position_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(ComputerCheckResult::class, 'computer_check_workstation_id');
    }

    /**
     * เครื่องที่ยังใช้งานอยู่ เรียงตามลำดับที่ตั้งไว้
     *
     * ใช้ตอน "เริ่มรอบใหม่" เท่านั้น การอ่านแบบตรวจย้อนหลังต้องอ่านจากแถวผลของ
     * รอบนั้น ไม่ใช่จาก scope นี้ ไม่งั้นใบเก่าจะเปลี่ยนจำนวนเครื่องตามผังปัจจุบัน
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('display_order')->orderBy('code');
    }
}
