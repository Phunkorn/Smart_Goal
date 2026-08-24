<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class UserSessionSecurity
{
    public static function invalidateAll(User $user): void
    {
        self::assertSupportedDriver();

        DB::table(self::sessionTable())
            ->where('user_id', $user->id)
            ->delete();
    }

    public static function invalidateOthers(User $user, string $currentSessionId): void
    {
        self::assertSupportedDriver();

        DB::table(self::sessionTable())
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    public static function assertSupportedDriver(): void
    {
        if (config('session.driver') !== 'database') {
            throw new LogicException(
                'Security-sensitive session revocation requires SESSION_DRIVER=database.'
            );
        }
    }

    private static function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
