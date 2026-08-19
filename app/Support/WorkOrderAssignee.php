<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderAssignee
{
    public const ROLE = 'user';

    public static function validationRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'integer',
            'exists:users,id,role,'.self::ROLE,
        ];
    }

    public static function query(): Builder
    {
        return User::query()->where('role', self::ROLE);
    }

    public static function findWithDepartment(?int $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        return self::query()->with('department')->find($userId);
    }
}
