<?php

namespace App\Http\Requests\Social;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateSocialPostRequest extends SocialPostRequest
{
    /** Response ของ policy — โพสต์ที่มองไม่เห็นตอบ 404 ไม่ใช่ 403 (ดู SocialCommentRequest::authorize) */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('post'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->sharedRules(),
            'remove_media' => ['nullable', 'array'],
            'remove_media.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<int, int> */
    public function removeMediaIds(): array
    {
        return array_map('intval', (array) $this->validated('remove_media', []));
    }
}
