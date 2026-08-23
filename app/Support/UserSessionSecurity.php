<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserSessionSecurity
{
    public static function invalidateAll(User $user): void
    {
        if (! self::usesDatabaseSessions()) {
            return;
        }

        DB::table(self::sessionTable())
            ->where('user_id', $user->id)
            ->delete();
    }

    public static function invalidateOthers(User $user, string $currentSessionId): void
    {
        if (! self::usesDatabaseSessions()) {
            return;
        }

        DB::table(self::sessionTable())
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    private static function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    private static function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
