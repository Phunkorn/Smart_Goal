<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'user', 'viewer'], true);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return in_array($user->role, ['admin', 'viewer'], true)
            || ($user->isDepartmentHead() && (
                $meeting->creator()->where('department_id', $user->department_id)->exists()
                || $meeting->attendees()->where('department_id', $user->department_id)->exists()
            ))
            || (int) $meeting->created_by === (int) $user->id
            || $meeting->attendees()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'user'], true);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->role === 'admin'
            || ($user->role === 'user' && (int) $meeting->created_by === (int) $user->id);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }
}
