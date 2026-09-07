<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * แม่แบบงานประจำ — ระบบสร้างรายการของแต่ละวันจากแม่แบบนี้ให้อัตโนมัติ
 *
 * แม่แบบเป็นของรายบุคคล (user_id) ในรอบนี้ แม่แบบระดับแผนกยังไม่รองรับ
 * เพราะ unique index กันสร้างซ้ำใน work_logs ตั้งอยู่บนสมมติฐาน per-user
 */
class WorkLogTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'work_log_category_id',
        'kind',
        'title',
        'details',
        'weekday_mask',
        'default_start_time',
        'default_duration_minutes',
        'starts_on',
        'ends_on',
        'last_materialized_on',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weekday_mask' => 'integer',
            'default_duration_minutes' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'last_materialized_on' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkLogCategory::class, 'work_log_category_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
