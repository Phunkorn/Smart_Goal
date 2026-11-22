<?php

namespace App\Http\Requests\Social;

use App\Models\SocialPost;

class StoreSocialPostRequest extends SocialPostRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', SocialPost::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->sharedRules();
    }
}
