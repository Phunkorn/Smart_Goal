<?php

namespace App\Services\Telegram;

use App\Models\SystemNotification;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\TelegramMessageComposer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * คิวข้อความขาออกของ Telegram และตัวส่งจริง
 *
 * เครื่อง production เป็น DirectAdmin ที่ไม่มี SSH จึงไม่มีทั้ง queue worker และ cron
 * (ดูคอมเมนต์ใน routes/console.php) การ dispatch job ตามปกติจะค้างในตาราง jobs ตลอดไป
 * ที่นี่จึงใช้วิธี INSERT แถวคิวตอนสร้างการแจ้งเตือน แล้วผูก callback ไว้กับ
 * Application::terminating() เพื่อยิง HTTP หลังคืน response ให้เบราว์เซอร์แล้ว
 *
 * ผลพลอยได้ที่สำคัญคือการยิง HTTP ไม่เคยเกิดขึ้นภายใน DB::transaction ของตัวเรียก
 * (TaskCommentService และ TaskStatusTransitionService สร้างการแจ้งเตือนในทรานแซกชัน)
 *
 * แถวที่ส่งไม่สำเร็จจะค้างเป็น pending และถูก request ถัดไปของใครก็ได้หยิบไปลองใหม่
 * จนครบ services.telegram.max_attempts
 */
class TelegramOutbox
{
    /** กัน callback ซ้ำเมื่อ request เดียวสร้างการแจ้งเตือนหลายฉบับ */
    private bool $drainScheduled = false;

    /** กัน drain ซ้อนตัวเองเมื่อการส่งไปกระตุ้นการสร้างการแจ้งเตือนอีกทอด */
    private bool $draining = false;

    public function __construct(private readonly TelegramClient $client) {}

    /**
     * เข้าคิวการแจ้งเตือนหนึ่งฉบับสำหรับผู้รับหนึ่งคน
     *
     * @param  string|null  $url  ลิงก์ลึกที่คำนวณจาก NotificationService::target() ของผู้รับคนนั้น
     *                            รับมาจากภายนอกเพื่อไม่ให้เกิด dependency วนกลับไปหา NotificationService
     */
    public function enqueue(SystemNotification $notification, User $recipient, ?string $url = null): ?TelegramMessage
    {
        if (! $this->client->enabled() || ! $recipient->receivesTelegramNotifications()) {
            return null;
        }

        $message = TelegramMessage::create([
            'user_id' => $recipient->id,
            'system_notification_id' => $notification->id,
            'chat_id' => $recipient->telegram_chat_id,
            'text' => TelegramMessageComposer::forNotification($notification, $url),
            'status' => TelegramMessage::STATUS_PENDING,
        ]);

        $this->scheduleDrain();

        return $message;
    }

    /**
     * ผูกการส่งไว้กับช่วงปิดท้าย request
     *
     * ใช้ Application::terminating() แทน dispatch()->afterResponse() เพราะได้ผลเหมือนกัน
     * แต่ไม่ต้องพึ่ง queue connection ใด ๆ เลย และทำงานทั้งใน HTTP และ artisan
     * (Console Kernel::terminate() ก็เรียก terminating callbacks เช่นกัน)
     */
    public function scheduleDrain(): void
    {
        if ($this->drainScheduled) {
            return;
        }

        $this->drainScheduled = true;

        app()->terminating(function (): void {
            $this->drain();
        });
    }

    /**
     * ส่งข้อความที่ค้างคิวออกไปให้มากที่สุดเท่าที่เพดานต่อรอบอนุญาต
     *
     * ห้ามโยน exception ออกจากเมธอดนี้เด็ดขาด เพราะถูกเรียกตอนปิดท้าย request
     * ที่ตอบผู้ใช้ไปแล้ว ความล้มเหลวใด ๆ ต้องจบลงที่ log กับคอลัมน์ last_error เท่านั้น
     */
    public function drain(): int
    {
        if ($this->draining || ! $this->client->enabled()) {
            return 0;
        }

        $this->draining = true;
        $sent = 0;

        try {
            foreach ($this->claim() as $message) {
                if ($this->deliver($message)) {
                    $sent++;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram outbox drain failed', ['exception' => $exception->getMessage()]);
        } finally {
            $this->draining = false;
        }

        return $sent;
    }

    /**
     * ส่งทันทีโดยไม่ผ่านคิว ใช้กับปุ่ม "ส่งข้อความทดสอบ" ที่ผู้ใช้ต้องเห็นผลลัพธ์จริง
     */
    public function sendNow(User $recipient, string $text): TelegramApiResult
    {
        if ($recipient->telegram_chat_id === null) {
            return TelegramApiResult::permanent('บัญชีนี้ยังไม่ได้เชื่อมต่อ Telegram');
        }

        $result = $this->client->sendMessage((int) $recipient->telegram_chat_id, $text);

        if ($result->shouldUnlink) {
            $this->unlink($recipient);
        }

        return $result;
    }

    /**
     * ตอบกลับในแชทที่ยังไม่รู้ว่าเป็นของผู้ใช้คนไหน (ใช้จาก webhook)
     */
    public function replyNow(int $chatId, string $text): TelegramApiResult
    {
        return $this->client->sendMessage($chatId, $text);
    }

    /**
     * จองแถวที่จะส่งในรอบนี้เป็นของตัวเองก่อนยิง HTTP
     *
     * ทุก request ที่สร้างการแจ้งเตือนจะ drain ด้วย ถ้าเลือกแถวแล้วส่งเลยโดยไม่จอง
     * request สองอันที่เกิดพร้อมกันจะหยิบแถวชุดเดียวกันไปส่ง ผู้ใช้จะได้ข้อความซ้ำสองครั้ง
     *
     * แถวที่ค้างสถานะ sending นานเกิน STALE_CLAIM_MINUTES ถูกดึงกลับมาจองใหม่ได้
     * เพื่อไม่ให้รอบที่ตายกลางคันทำให้ข้อความหายไปเงียบ ๆ
     *
     * @return Collection<int, TelegramMessage>
     */
    private function claim(): Collection
    {
        $limit = max(1, (int) config('services.telegram.drain_limit', 40));

        return DB::transaction(function () use ($limit): Collection {
            $ids = TelegramMessage::query()
                ->where(fn ($query) => $query
                    ->where('status', TelegramMessage::STATUS_PENDING)
                    ->orWhere(fn ($stale) => $stale
                        ->where('status', TelegramMessage::STATUS_SENDING)
                        ->where('updated_at', '<', now()->subMinutes(TelegramMessage::STALE_CLAIM_MINUTES))))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            TelegramMessage::query()->whereIn('id', $ids)->update([
                'status' => TelegramMessage::STATUS_SENDING,
                'updated_at' => now(),
            ]);

            return TelegramMessage::query()->whereIn('id', $ids)->orderBy('id')->get();
        });
    }

    private function deliver(TelegramMessage $message): bool
    {
        $result = $this->client->sendMessage((int) $message->chat_id, $message->text);

        if ($result->ok) {
            $message->forceFill([
                'status' => TelegramMessage::STATUS_SENT,
                'attempts' => $message->attempts + 1,
                'last_error' => null,
                'sent_at' => now(),
            ])->save();

            return true;
        }

        $attempts = $message->attempts + 1;
        $exhausted = $attempts >= max(1, (int) config('services.telegram.max_attempts', 3));

        $message->forceFill([
            'status' => ($result->retryable && ! $exhausted)
                ? TelegramMessage::STATUS_PENDING
                : TelegramMessage::STATUS_FAILED,
            'attempts' => $attempts,
            'last_error' => mb_substr((string) $result->description, 0, 1000),
        ])->save();

        if ($result->shouldUnlink && $message->user) {
            $this->unlink($message->user);
        }

        Log::warning('Telegram message not delivered', [
            'telegram_message_id' => $message->id,
            'user_id' => $message->user_id,
            'error_code' => $result->errorCode,
            'description' => $result->description,
            'attempts' => $attempts,
        ]);

        return false;
    }

    /**
     * ล้างการผูกบัญชีเมื่อ chat_id ใช้ไม่ได้อีกแล้ว
     *
     * ต้องล้างทั้งชุด ไม่ใช่แค่ปิดสวิตช์ เพราะปัญหาอยู่ที่ปลายทางไม่ใช่ความตั้งใจของผู้ใช้
     * ผู้ใช้จะเห็นหน้าตั้งค่ากลับเป็น "ยังไม่ได้เชื่อมต่อ" และผูกใหม่ได้ทันที
     */
    public function unlink(User $user): void
    {
        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
            'telegram_notifications_enabled' => true,
        ])->save();

        TelegramMessage::query()
            ->where('user_id', $user->id)
            ->pending()
            ->update([
                'status' => TelegramMessage::STATUS_FAILED,
                'last_error' => 'ยกเลิกการเชื่อมต่อ Telegram แล้ว',
                'updated_at' => now(),
            ]);
    }
}
