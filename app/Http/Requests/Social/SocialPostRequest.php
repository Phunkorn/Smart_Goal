<?php

namespace App\Http\Requests\Social;

use App\Models\SocialPost;
use App\Services\SocialPostService;
use App\Support\SocialImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * กติกาโครงสร้างที่สร้างและแก้ไขโพสต์ใช้ร่วมกัน
 *
 * ที่นี่ตรวจแค่รูปทรงของคำขอ (ความยาวข้อความ จำนวนไฟล์ และว่าเป็นไฟล์ที่อัปโหลดสำเร็จ)
 * การตรวจเนื้อไฟล์จริงและกติกา "ต้องมีข้อความหรือรูปอย่างน้อยหนึ่งอย่าง" อยู่ที่
 * SocialPostMediaStore / SocialPostService ที่เดียว เพราะตอนแก้ไขต้องนับรวมรูปเดิมที่เหลืออยู่
 */
abstract class SocialPostRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['body' => SocialPostService::normalizeText($this->input('body'))]);
    }

    /** @return array<string, array<int, mixed>> */
    protected function sharedRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.(int) config('social.post_max_length', 5000)],
            'images' => ['nullable', 'array', 'max:'.SocialImageRules::maxImages()],
            'images.*' => ['file'],
            // แผนกเป้าหมายไม่ใช่ค่าที่รับจาก client — SocialPostService ใช้แผนกจริงของผู้โพสต์เสมอ
            'visibility' => ['nullable', 'string', Rule::in(SocialPost::VISIBILITIES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.max' => 'ข้อความโพสต์ต้องไม่เกิน :max ตัวอักษร',
            'images.max' => SocialImageRules::tooManyMessage(),
            'images.*.file' => 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
            'remove_media.*.integer' => 'รูปที่ต้องการลบไม่ถูกต้อง',
            'visibility.in' => 'ระดับการมองเห็นโพสต์ไม่ถูกต้อง',
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body', '');
    }

    /** ระดับการมองเห็นที่ผู้ใช้เลือก — null คือไม่ได้ส่งมา (สร้าง = ทุกคน, แก้ไข = คงค่าเดิม) */
    public function visibility(): ?string
    {
        $visibility = $this->validated('visibility');

        return is_string($visibility) && $visibility !== '' ? $visibility : null;
    }

    /** @return array<int, mixed> */
    public function images(): array
    {
        return (array) $this->file('images', []);
    }
}
