<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * ตัวห่อ Telegram Bot API แบบบาง ๆ
 *
 * เป็น integration ภายนอกตัวแรกของโปรเจกต์ จึงรวมการอ่าน config การตั้ง timeout
 * และการแปลรหัสข้อผิดพลาดไว้ที่นี่ที่เดียว เพื่อไม่ให้ตัวเรียกต้องรู้จักรูปแบบ
 * ของ Telegram ผู้เรียกทุกที่ได้ TelegramApiResult กลับไปเสมอ ไม่มี exception หลุดออก
 */
class TelegramClient
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    public function enabled(): bool
    {
        return (bool) config('services.telegram.enabled') && $this->configured();
    }

    public function sendMessage(int $chatId, string $html): TelegramApiResult
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            // ลิงก์ในข้อความเป็นลิงก์เข้าระบบซึ่งต้องล็อกอิน การ์ดพรีวิวจึงว่างเปล่าเสมอ
            'disable_web_page_preview' => true,
        ]);
    }

    public function getMe(): TelegramApiResult
    {
        return $this->call('getMe');
    }

    public function setWebhook(string $url, ?string $secret = null): TelegramApiResult
    {
        return $this->call('setWebhook', array_filter([
            'url' => $url,
            'secret_token' => $secret ?? config('services.telegram.webhook_secret'),
            // รับเฉพาะข้อความในแชทส่วนตัว ไม่รับ event อื่นเพื่อลด traffic ที่ไม่ได้ใช้
            'allowed_updates' => json_encode(['message']),
            'drop_pending_updates' => 'true',
        ], fn ($value) => filled($value)));
    }

    public function getWebhookInfo(): TelegramApiResult
    {
        return $this->call('getWebhookInfo');
    }

    private function call(string $method, array $parameters = []): TelegramApiResult
    {
        if (! $this->configured()) {
            return TelegramApiResult::permanent('ยังไม่ได้ตั้งค่า TELEGRAM_BOT_TOKEN');
        }

        try {
            $response = Http::timeout((int) config('services.telegram.timeout', 8))
                ->asForm()
                ->post($this->endpoint($method), $parameters);
        } catch (ConnectionException $exception) {
            return TelegramApiResult::retryable('เชื่อมต่อ Telegram ไม่ได้: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return TelegramApiResult::retryable('เรียก Telegram ไม่สำเร็จ: '.$exception->getMessage());
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): TelegramApiResult
    {
        $body = $response->json();
        $description = (string) ($body['description'] ?? $response->body());
        $errorCode = isset($body['error_code']) ? (int) $body['error_code'] : $response->status();

        if ($response->successful() && ($body['ok'] ?? false)) {
            return TelegramApiResult::success(is_array($body['result'] ?? null) ? $body['result'] : []);
        }

        // ผู้ใช้บล็อกบอท ลบแชท หรือปิดบัญชี — chat_id นี้ใช้ไม่ได้อีกแล้ว
        if ($errorCode === 403 || Str::contains(Str::lower($description), ['chat not found', 'user is deactivated', 'bot was blocked'])) {
            return TelegramApiResult::unlink($description, $errorCode);
        }

        if ($errorCode === 429 || $errorCode >= 500) {
            return TelegramApiResult::retryable($description, $errorCode);
        }

        return TelegramApiResult::permanent($description, $errorCode);
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
