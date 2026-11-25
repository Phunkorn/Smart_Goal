<?php

namespace App\Services;

use App\Models\ProfileFrame;
use App\Models\User;
use App\Support\ProfileCoverImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProfileAppearanceService
{
    public function update(User $user, array $attributes, ?UploadedFile $cover): User
    {
        $newPath = $cover ? ProfileCoverImage::store($cover, (int) $user->getKey()) : null;

        try {
            [$saved, $oldPath] = DB::transaction(function () use ($user, $attributes, $newPath): array {
                // Serialize competing edits so each replaced file has exactly one owner.
                $saved = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $oldPath = $saved->profile_cover;
                $frame = null;
                if ($attributes['avatar_frame'] !== 'none') {
                    $frame = ProfileFrame::query()->where('slug', $attributes['avatar_frame'])->sharedLock()->first(['slug', 'is_active']);
                    if (! $frame || (! $frame->is_active && $saved->avatar_frame !== $frame->slug)) {
                        throw ValidationException::withMessages(['avatar_frame' => 'กรุณาเลือกกรอบโปรไฟล์ที่เปิดให้ใช้งาน'])
                            ->errorBag('appearance');
                    }
                }
                $saved->avatar_frame = $frame?->slug;
                $saved->setRelation('avatarFrame', $frame);

                if ($newPath !== null || ($attributes['remove_profile_cover'] ?? false)) {
                    $saved->profile_cover = $newPath;
                }

                $saved->save();

                return [$saved, $oldPath];
            });
        } catch (Throwable $error) {
            ProfileCoverImage::delete((int) $user->getKey(), $newPath);
            throw $error;
        }

        if ($oldPath && $oldPath !== $saved->profile_cover) {
            ProfileCoverImage::delete((int) $user->getKey(), $oldPath);
        }

        return $saved;
    }
}
