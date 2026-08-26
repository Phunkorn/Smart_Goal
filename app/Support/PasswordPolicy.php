<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * The minimum length enforced by rule(); also drives every UI hint.
     */
    public const MIN_LENGTH = 12;

    /**
     * The single password standard for new, temporary, and changed passwords.
     */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * The Thai wording shown beside every password field and returned on failure.
     */
    public static function description(): string
    {
        return 'รหัสผ่านต้องมีอย่างน้อย '.self::MIN_LENGTH.' ตัวอักษร และต้องมีตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์';
    }

    /**
     * Validation messages for a field validated with rule().
     *
     * Password::passes() forwards the outer validator's custom messages to the
     * inner validator, so the length failure resolves "<attribute>.min" while the
     * character-class failures resolve "<attribute>.password.<check>".
     *
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'password'): array
    {
        $description = self::description();

        return [
            $attribute.'.min' => $description,
            $attribute.'.password.mixed' => $description,
            $attribute.'.password.letters' => $description,
            $attribute.'.password.numbers' => $description,
            $attribute.'.password.symbols' => $description,
        ];
    }
}
