<?php

namespace App\Support;

use App\Models\User;

final class ProfileAppearance
{
    public static function frames(): array
    {
        return config('profile-appearance.frames');
    }

    public static function frame(?User $user): string
    {
        $key = $user?->avatar_frame;

        return array_key_exists((string) $key, self::frames()) ? $key : 'none';
    }

    public static function coverUrl(User $user): ?string
    {
        return $user->profile_cover ? route('media.profile-cover', [
            'user' => $user,
            'v' => substr(hash('sha256', $user->profile_cover), 0, 12),
        ]) : null;
    }
}
