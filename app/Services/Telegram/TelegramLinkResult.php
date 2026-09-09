<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * ผลการผูกบัญชีจากรหัสที่ผู้ใช้ส่งให้บอท
 *
 * ต้องแยก "รหัสใช้ไม่ได้" ออกจาก "แชทนี้เป็นของคนอื่นแล้ว" เพราะสองกรณีนี้
 * ผู้ใช้ต้องแก้คนละแบบ และข้อความตอบกลับในแชทต้องบอกให้ตรงเรื่อง
 */
class TelegramLinkResult
{
    public const LINKED = 'linked';

    public const INVALID = 'invalid';

    public const CHAT_TAKEN = 'chat_taken';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
    ) {}

    public static function linked(User $user): self
    {
        return new self(self::LINKED, $user);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function chatTaken(): self
    {
        return new self(self::CHAT_TAKEN);
    }

    public function isLinked(): bool
    {
        return $this->status === self::LINKED;
    }
}
