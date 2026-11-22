<?php

namespace App\Models;

use App\Support\ProtectedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * รูปภาพของโพสต์ในชุมชน
 *
 * ไฟล์เก็บใน private disk (ProtectedMedia) และเสิร์ฟผ่าน MediaController::socialPostMedia
 * ซึ่งตรวจสิทธิ์จากโพสต์แม่ ห้ามสร้าง URL จาก file_path ตรง ๆ
 *
 * ไฟล์ถูกลบเมื่อแถวถูกลบเท่านั้น ได้แก่ ผู้เขียนเอารูปออกตอนแก้ไขโพสต์ หรือโพสต์ถูกลบถาวร
 * จากถังขยะ (TrashRetention ไล่ลบแถวนี้ทีละแถวผ่าน TrashRestorers::FILE_RELATIONS)
 * การลบโพสต์แบบ soft delete ไม่แตะแถวนี้ รูปจึงกลับมาครบเมื่อกู้คืน
 *
 * ลบไฟล์หลัง commit เท่านั้น — ถ้าทรานแซกชันของการแก้ไขล้มแล้วแถวถูกย้อนกลับ
 * ไฟล์ต้องยังอยู่ ไม่ใช่กลายเป็นแถวที่ชี้ไปยังไฟล์ที่ไม่มีแล้ว
 */
class SocialPostMedia extends Model
{
    public const DIRECTORY = 'social-posts';

    protected $table = 'social_post_media';

    protected $fillable = [
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $media): void {
            $path = $media->file_path;

            DB::afterCommit(fn () => ProtectedMedia::deleteAttachment($path));
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public static function directoryFor(SocialPost $post): string
    {
        return self::DIRECTORY.'/'.$post->id;
    }
}
