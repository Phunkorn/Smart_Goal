<?php

namespace App\Support;

use App\Models\ProfileFrame;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ProfileAppearance
{
    public static function frames(): Collection
    {
        return ProfileFrame::query()->where('is_active', true)->orderBy('sort_order')->orderBy('slug')
            ->get(['slug', 'name', 'animated', 'asset_path', 'preview_path', 'is_active']);
    }

    public static function previewFrame(User $user, mixed $oldFrame): ?ProfileFrame
    {
        $saved = $user->avatarFrame;
        if ($oldFrame === 'none') {
            return null;
        }

        if (is_string($oldFrame) && $oldFrame !== $saved?->slug && strlen($oldFrame) <= 24) {
            return ProfileFrame::query()->where('slug', $oldFrame)->where('is_active', true)->first() ?? $saved;
        }

        return $saved;
    }

    public static function frame(?User $user): string
    {
        return $user?->avatarFrame?->slug ?? 'none';
    }

    public static function coverUrl(User $user): ?string
    {
        return $user->profile_cover ? route('media.profile-cover', [
            'user' => $user,
            'v' => substr(hash('sha256', $user->profile_cover), 0, 12),
        ]) : null;
    }
}
