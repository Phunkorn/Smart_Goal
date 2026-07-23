<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * The single password standard for new, temporary, and changed passwords.
     */
    public static function rule(): Password
    {
        return Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}
