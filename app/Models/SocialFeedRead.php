<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * เวลาที่ผู้ใช้เปิดดูฟีดชุมชนล่าสุด — หนึ่งแถวต่อผู้ใช้ ดู App\Services\SocialFeedReadService
 */
class SocialFeedRead extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['user_id', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
