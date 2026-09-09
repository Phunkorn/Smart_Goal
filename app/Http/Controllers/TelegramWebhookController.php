<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\TelegramLinkResult;
use App\Services\Telegram\TelegramLinkService;
use App\Services\Telegram\TelegramOutbox;
use App\Support\TelegramMessageComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ปลายทางที่ Telegram ยิง update เข้ามา
 *
 * เส้นทางนี้อยู่นอกกลุ่ม auth และถูกยกเว้น CSRF (ดู bootstrap/app.php) การพิสูจน์ตัวตน
 * จึงมาจาก secret token ที่ Telegram แนบมาในเฮดเดอร์ทุกครั้งเท่านั้น ซึ่งเป็นค่าที่เรา
 * กำหนดเองตอน setWebhook และรู้กันแค่สองฝ่าย
 *
 * ตอบ 200 เสมอเมื่อ secret ถูกต้อง แม้จะประมวลผลไม่ได้ เพราะ Telegram จะยิงซ้ำรัว ๆ
 * กับทุก response ที่ไม่ใช่ 2xx และทำให้ update เดิมค้างคิวอยู่ฝั่งเขา
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramLinkService $links,
        private readonly TelegramOutbox $outbox,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $message = $request->input('message');
        $chatId = (int) data_get($message, 'chat.id');
        $text = trim((string) data_get($message, 'text'));

        // สนใจเฉพาะข้อความในแชทส่วนตัว ระบบนี้ไม่ยิงเข้ากลุ่มโดยเจตนา
        if ($chatId === 0 || data_get($message, 'chat.type') !== 'private') {
            return response()->json(['ok' => true]);
        }

        $this->dispatchCommand($chatId, $text, data_get($message, 'from.username'));

        return response()->json(['ok' => true]);
    }

    private function dispatchCommand(int $chatId, string $text, ?string $username): void
    {
        [$command, $argument] = $this->parse($text);

        match ($command) {
            '/start' => $this->handleStart($chatId, $argument, $username),
            '/stop' => $this->handleStop($chatId),
            '/status' => $this->handleStatus($chatId),
            default => $this->outbox->replyNow($chatId, TelegramMessageComposer::help()),
        };
    }

    private function handleStart(int $chatId, ?string $token, ?string $username): void
    {
        // /start เปล่า ๆ จากคนที่ผูกไว้แล้ว หมายถึงขอกลับมารับแจ้งเตือนอีกครั้ง
        if ($token === null) {
            $user = $this->linkedUser($chatId);

            if (! $user) {
                $this->outbox->replyNow($chatId, TelegramMessageComposer::help());

                return;
            }

            $this->links->setEnabled($user, true);
            $this->outbox->replyNow($chatId, TelegramMessageComposer::resumed($user->name));

            return;
        }

        $result = $this->links->consume($token, $chatId, $username);

        $reply = match ($result->status) {
            TelegramLinkResult::LINKED => TelegramMessageComposer::linked($result->user->name),
            TelegramLinkResult::CHAT_TAKEN => '⚠️ แชทนี้ผูกกับบัญชีอื่นอยู่แล้ว กรุณายกเลิกการเชื่อมต่อของบัญชีนั้นที่หน้าตั้งค่าก่อน',
            default => '⚠️ รหัสเชื่อมต่อไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรหัสใหม่จากหน้าตั้งค่าในเว็บ Smart Goal',
        };

        if ($result->status === TelegramLinkResult::INVALID) {
            Log::info('Telegram link token rejected', ['chat_id' => $chatId]);
        }

        $this->outbox->replyNow($chatId, $reply);
    }

    private function handleStop(int $chatId): void
    {
        $user = $this->linkedUser($chatId);

        if (! $user) {
            $this->outbox->replyNow($chatId, TelegramMessageComposer::help());

            return;
        }

        $this->links->setEnabled($user, false);
        $this->outbox->replyNow($chatId, TelegramMessageComposer::stopped());
    }

    private function handleStatus(int $chatId): void
    {
        $user = $this->linkedUser($chatId);

        $this->outbox->replyNow(
            $chatId,
            $user
                ? TelegramMessageComposer::status($user->name, (bool) $user->telegram_notifications_enabled)
                : TelegramMessageComposer::help()
        );
    }

    private function linkedUser(int $chatId): ?User
    {
        return User::query()->where('telegram_chat_id', $chatId)->first();
    }

    /**
     * แยกคำสั่งกับพารามิเตอร์
     *
     * Telegram ต่อท้ายคำสั่งด้วย @ชื่อบอทเมื่อมีบอทหลายตัวอยู่ในห้องเดียวกัน
     * จึงต้องตัดส่วนนั้นทิ้งก่อนเทียบ
     *
     * @return array{0: string, 1: string|null}
     */
    private function parse(string $text): array
    {
        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $command = Str::lower(Str::before((string) ($parts[0] ?? ''), '@'));
        $argument = trim((string) ($parts[1] ?? ''));

        return [$command, $argument === '' ? null : $argument];
    }
}
