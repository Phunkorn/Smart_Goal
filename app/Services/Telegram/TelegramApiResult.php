<?php

namespace App\Services\Telegram;

/**
 * ผลการเรียก Telegram Bot API หนึ่งครั้ง
 *
 * แยกความล้มเหลวออกเป็นสามแบบ เพราะแต่ละแบบต้องจัดการต่างกัน
 * - retryable  : เครือข่ายล่ม / 429 / 5xx  → ลองใหม่รอบหน้า
 * - shouldUnlink : ผู้ใช้บล็อกบอทหรือลบแชท → ล้างการผูกบัญชี ไม่ต้องลองอีก
 * - นอกนั้น    : ข้อความผิดรูป ฯลฯ           → เลิกส่ง แต่ไม่แตะการผูกบัญชี
 */
class TelegramApiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $retryable = false,
        public readonly bool $shouldUnlink = false,
        public readonly ?int $errorCode = null,
        public readonly ?string $description = null,
        public readonly array $payload = [],
    ) {}

    public static function success(array $payload = []): self
    {
        return new self(ok: true, payload: $payload);
    }

    public static function retryable(string $description, ?int $errorCode = null): self
    {
        return new self(ok: false, retryable: true, errorCode: $errorCode, description: $description);
    }

    public static function unlink(string $description, ?int $errorCode = null): self
    {
        return new self(ok: false, shouldUnlink: true, errorCode: $errorCode, description: $description);
    }

    public static function permanent(string $description, ?int $errorCode = null): self
    {
        return new self(ok: false, errorCode: $errorCode, description: $description);
    }
}
