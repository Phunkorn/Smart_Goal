<?php

namespace App\Http\Requests\Social;

use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\SocialPostService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * เขียนหรือแก้ไขความคิดเห็นในชุมชน
 *
 * ใช้คลาสเดียวทั้งสองทาง เพราะกติกาข้อความเหมือนกัน ต่างกันแค่สิทธิ์:
 *   POST  /social/posts/{post}/comments   → SocialPostPolicy::interact (โพสต์ต้องยังอยู่)
 *   PATCH /social/comments/{comment}      → SocialCommentPolicy::update (เจ้าของเท่านั้น)
 */
class SocialCommentRequest extends FormRequest
{
    /**
     * คืน Response ของ policy ตรง ๆ (ไม่ใช่ bool) เพื่อให้โพสต์ที่มองไม่เห็นตอบ 404 ตามที่ policy กำหนด
     * ถ้าคืน bool Laravel จะแปลงการปฏิเสธทุกแบบเป็น 403 ซึ่งบอกใบ้ว่าโพสต์นั้นมีอยู่
     */
    public function authorize(): Response|bool
    {
        $gate = Gate::forUser($this->user());
        $comment = $this->route('comment');

        if ($comment instanceof SocialComment) {
            return $gate->inspect('update', $comment);
        }

        $post = $this->route('post');

        return $post instanceof SocialPost ? $gate->inspect('interact', $post) : false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => SocialPostService::normalizeText($this->input('body'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('social.comment_max_length', 2000)],
            'parent_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'กรุณาพิมพ์ความคิดเห็น',
            'body.max' => 'ความคิดเห็นต้องไม่เกิน :max ตัวอักษร',
            'parent_id.integer' => 'ความคิดเห็นที่ต้องการตอบกลับไม่ถูกต้อง',
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }

    public function parentId(): ?int
    {
        $parentId = $this->validated('parent_id');

        return $parentId === null ? null : (int) $parentId;
    }
}
