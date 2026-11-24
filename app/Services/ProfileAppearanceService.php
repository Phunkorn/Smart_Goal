<?php

namespace App\Services;

use App\Models\User;
use App\Support\ProtectedMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProfileAppearanceService
{
    public function update(User $user, array $attributes, ?UploadedFile $cover): User
    {
        $newPath = $cover ? ProtectedMedia::storeAttachment($cover, 'profile-covers/'.$user->getKey()) : null;

        try {
            [$saved, $oldPath] = DB::transaction(function () use ($user, $attributes, $newPath): array {
                // Serialize competing edits so each replaced file has exactly one owner.
                $saved = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $oldPath = $saved->profile_cover;
                $saved->avatar_frame = $attributes['avatar_frame'] === 'none' ? null : $attributes['avatar_frame'];

                if ($newPath !== null || ($attributes['remove_profile_cover'] ?? false)) {
                    $saved->profile_cover = $newPath;
                }

                $saved->save();

                return [$saved, $oldPath];
            });
        } catch (Throwable $error) {
            ProtectedMedia::deleteAttachment($newPath);
            throw $error;
        }

        if ($oldPath && $oldPath !== $saved->profile_cover) {
            ProtectedMedia::deleteAttachment($oldPath);
        }

        return $saved;
    }
}
